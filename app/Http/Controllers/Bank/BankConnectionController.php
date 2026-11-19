<?php

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Presenters\BankPresenter;
use App\Http\Requests\Bank\StoreBankConnectionRequest;
use App\Models\BankConnection;
use App\Services\Bank\BankCatalogService;
use App\Services\Bank\BankConnectionService;
use App\Services\Bank\BankSyncService;
use App\Services\Bank\BankWebhookService;
use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The member's banks connected through Pluggy: accounts, cards with their
 * bills, and investments. Connecting, syncing and disconnecting. Everything
 * here is private to the member.
 */
class BankConnectionController extends Controller
{
    use InteractsWithHousehold;

    public function index(Request $request, PluggyClient $pluggy, BankWebhookService $webhooks, BankPresenter $presenter, BankCatalogService $catalog): Response
    {
        $user = $this->user($request);

        $connections = BankConnection::query()
            ->where('user_id', $user->id)
            ->with(['accounts.bills', 'investments'])
            ->orderBy('id')
            ->get();

        return Inertia::render('bank/accounts', [
            'configured' => $pluggy->configured(),
            'webhook' => [
                'configured' => $webhooks->configured(),
                'url' => route('webhooks.pluggy'),
            ],
            'connectScript' => (string) config('bank.connect_script'),
            'connections' => $connections->map(fn (BankConnection $connection) => $presenter->connection($connection))->all(),
            'inboxCount' => $catalog->pendingOf($user)->count(),
        ]);
    }

    /**
     * A connect token for the Pluggy Connect widget; with a connection, the
     * widget opens it to renew the authorization.
     */
    public function token(Request $request, PluggyClient $pluggy, BankConnectionService $connections): JsonResponse
    {
        $user = $this->user($request);
        $itemId = null;

        if ($request->filled('connection')) {
            $connection = BankConnection::query()->where('user_id', $user->id)->findOrFail($request->integer('connection'));
            $itemId = $connection->item_id;
        }

        try {
            $token = $pluggy->connectToken($connections->clientUserId($user), $itemId);
        } catch (PluggyException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Renewing needs the item id in the widget too (updateItem).
        return response()->json(['token' => $token, 'item_id' => $itemId]);
    }

    public function store(StoreBankConnectionRequest $request, BankConnectionService $connections, BankSyncService $sync): RedirectResponse
    {
        $connection = $connections->connect($this->user($request), $request->itemId());

        try {
            $created = (int) $sync->sync($connection);
            $this->toast($created === 1 ? 'Banco conectado. 1 transação importada.' : "Banco conectado. {$created} transações importadas.");
        } catch (PluggyException $exception) {
            $this->toast('Banco conectado, mas a primeira busca falhou: '.$exception->getMessage(), 'warning');
        }

        return to_route('bank.index');
    }

    /**
     * Fetch the connection's data from Pluggy now. It reads what Pluggy has
     * (Meu Pluggy refreshes it from the bank once a day).
     */
    public function sync(Request $request, BankConnection $connection, BankSyncService $sync): RedirectResponse
    {
        Gate::authorize('update', $connection);

        try {
            $created = $sync->sync($connection);

            if ($created === null) {
                $this->toast('Uma sincronização desta conexão já está em andamento.', 'info');
            } else {
                $this->toast(match ($created) {
                    0 => 'Sincronizado. Nenhuma transação nova.',
                    1 => 'Sincronizado. 1 transação nova.',
                    default => "Sincronizado. {$created} transações novas.",
                });
            }
        } catch (PluggyException $exception) {
            $this->toast($exception->getMessage(), 'error');
        }

        return back();
    }

    public function destroy(Request $request, BankConnection $connection, BankConnectionService $connections): RedirectResponse
    {
        Gate::authorize('delete', $connection);

        $revoked = $connections->disconnect($connection, $this->user($request));

        $this->toast($revoked
            ? 'Banco desconectado e dados importados apagados. As despesas já catalogadas continuam.'
            : 'Dados importados apagados, mas a Pluggy não confirmou a revogação. Remova a conexão também no painel da Pluggy ou em meu.pluggy.ai.', $revoked ? 'success' : 'warning');

        return to_route('bank.index');
    }
}
