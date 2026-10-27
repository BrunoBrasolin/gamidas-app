<?php

namespace App\Http\Controllers\Watched;

use App\Http\Controllers\Controller;
use App\Services\WatchHistoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Receives the events of the Jellyfin Webhook plugin. The user is identified
 * by the token in the X-Gamidas-Token header (or a bearer token), since the
 * request comes from the Jellyfin server and carries no session.
 */
class JellyfinWebhookController extends Controller
{
    public function __invoke(Request $request, WatchHistoryService $history): JsonResponse
    {
        $token = (string) ($request->header('X-Gamidas-Token') ?? $request->bearerToken() ?? '');
        $connection = $history->connectionForToken(trim($token));

        if ($connection === null) {
            return response()->json(['message' => 'Token inválido.'], 401);
        }

        /** @var array<array-key, mixed> $data */
        $data = $request->json()->all();

        try {
            $status = $history->record($connection, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['status' => $status]);
    }
}
