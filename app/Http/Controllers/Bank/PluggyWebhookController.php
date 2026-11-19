<?php

namespace App\Http\Controllers\Bank;

use App\Http\Controllers\Controller;
use App\Services\Bank\BankWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Pluggy's webhooks (registered by `php artisan bank:webhook`). It
 * answers right away; the sync runs after the response.
 */
class PluggyWebhookController extends Controller
{
    public function __invoke(Request $request, BankWebhookService $webhooks): JsonResponse
    {
        if (! $webhooks->authentic($request)) {
            return response()->json(['message' => 'Não autorizado.'], 401);
        }

        /** @var array<array-key, mixed> $payload */
        $payload = $request->json()->all();

        if (! is_string($payload['event'] ?? null)) {
            return response()->json(['message' => 'Evento da Pluggy inválido.'], 422);
        }

        return response()->json(['status' => $webhooks->handle($payload)]);
    }
}
