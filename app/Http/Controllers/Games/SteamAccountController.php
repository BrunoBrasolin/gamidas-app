<?php

namespace App\Http\Controllers\Games;

use App\Http\Controllers\Concerns\InteractsWithHousehold;
use App\Http\Controllers\Controller;
use App\Http\Requests\Games\ConnectSteamRequest;
use App\Jobs\SyncSteamAchievements;
use App\Models\SteamAccount;
use App\Models\User;
use App\Services\Steam\SteamClient;
use App\Services\Steam\SteamException;
use App\Services\Steam\SteamOpenId;
use App\Services\Steam\SteamProfileResolver;
use App\Services\Steam\SteamSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Connecting, syncing and disconnecting the signed-in user's Steam account.
 * Each user connects their own account, either by signing in through Steam
 * or by typing their public profile.
 */
class SteamAccountController extends Controller
{
    use InteractsWithHousehold;

    /** Session key holding the state of a sign-in through Steam. */
    private const STATE = 'games.steam_openid_state';

    public function __construct(private readonly SteamClient $steam, private readonly SteamSyncService $sync)
    {
        //
    }

    /**
     * Send the user to Steam's sign-in page.
     */
    public function redirect(Request $request, SteamOpenId $openId): RedirectResponse
    {
        if (! $this->steam->configured()) {
            $this->toast(SteamException::notConfigured()->getMessage(), 'error');

            return to_route('games.index');
        }

        $state = Str::random(40);
        $request->session()->put(self::STATE, $state);

        return redirect()->away($openId->loginUrl(route('games.steam.callback', ['state' => $state]), url('/')));
    }

    /**
     * Steam sends the user back here; Steam confirms who signed in.
     */
    public function callback(Request $request, SteamOpenId $openId): RedirectResponse
    {
        $state = $request->session()->pull(self::STATE);

        if (! is_string($state) || ! hash_equals($state, $request->string('state')->toString())) {
            $this->toast('O login pela Steam expirou ou não foi iniciado aqui. Tente de novo.', 'error');

            return to_route('games.index');
        }

        try {
            $steamId = $openId->verify(
                SteamOpenId::params($request->getQueryString() ?? ''),
                route('games.steam.callback', ['state' => $state]),
            );
        } catch (SteamException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return to_route('games.index');
        }

        if ($steamId === null) {
            $this->toast('A Steam não confirmou o login. Tente de novo.', 'error');

            return to_route('games.index');
        }

        return $this->connect($this->user($request), $steamId);
    }

    /**
     * Connect by typing the profile (SteamID64, profile URL or custom URL).
     */
    public function store(ConnectSteamRequest $request, SteamProfileResolver $resolver): RedirectResponse
    {
        if (! $this->steam->configured()) {
            throw ValidationException::withMessages(['profile' => SteamException::notConfigured()->getMessage()]);
        }

        try {
            $steamId = $resolver->resolve($request->profile());
        } catch (SteamException $exception) {
            throw ValidationException::withMessages(['profile' => $exception->getMessage()]);
        }

        if ($steamId === null) {
            throw ValidationException::withMessages(['profile' => 'Nenhum perfil da Steam encontrado. Use o link do perfil, o SteamID64 (17 dígitos) ou o nome do link personalizado.']);
        }

        return $this->connect($this->user($request), $steamId);
    }

    public function sync(Request $request): RedirectResponse
    {
        $account = $this->account($request);

        try {
            $this->sync->sync($account);
        } catch (SteamException $exception) {
            $this->toast('Não foi possível sincronizar.', 'error', $exception->getMessage());

            return back();
        }

        SyncSteamAchievements::dispatchAfterResponse($account->id);

        $account->sync_error === null
            ? $this->toast('Steam sincronizada.')
            : $this->toast('Perfil atualizado, mas a biblioteca não.', 'warning', $account->sync_error);

        return back();
    }

    /**
     * Disconnect: the account and everything synced from it are deleted.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->account($request)->delete();

        $this->toast('Steam desconectada.');

        return to_route('games.index');
    }

    private function connect(User $user, string $steamId): RedirectResponse
    {
        try {
            $account = $this->sync->connect($user, $steamId);
        } catch (SteamException $exception) {
            $this->toast($exception->getMessage(), 'error');

            return to_route('games.index');
        }

        try {
            $this->sync->sync($account);
        } catch (SteamException $exception) {
            $this->toast('Steam conectada, mas a primeira sincronização falhou.', 'warning', $exception->getMessage());

            return to_route('games.index');
        }

        SyncSteamAchievements::dispatchAfterResponse($account->id);

        $account->sync_error === null
            ? $this->toast('Steam conectada.', 'success', 'As conquistas dos jogos chegam aos poucos nas próximas sincronizações.')
            : $this->toast('Steam conectada, mas a biblioteca é privada.', 'warning', $account->sync_error);

        return to_route('games.index');
    }

    private function account(Request $request): SteamAccount
    {
        $account = $this->user($request)->steamAccount;

        abort_if($account === null, 404);

        return $account;
    }
}
