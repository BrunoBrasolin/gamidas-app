<?php

namespace App\Console\Commands;

use App\Services\Pluggy\PluggyClient;
use App\Services\Pluggy\PluggyException;
use Illuminate\Console\Command;

class RegisterBankWebhookCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'bank:webhook';

    /**
     * @var string
     */
    protected $description = 'Register (or update) the Pluggy webhook of this application, with the PLUGGY_WEBHOOK_TOKEN header';

    public function handle(PluggyClient $pluggy): int
    {
        $token = (string) config('services.pluggy.webhook_token');
        $url = route('webhooks.pluggy');

        if (! $pluggy->configured()) {
            $this->components->error('Defina PLUGGY_CLIENT_ID e PLUGGY_CLIENT_SECRET no .env.');

            return self::FAILURE;
        }

        if (strlen($token) < 32) {
            $this->components->error('Defina PLUGGY_WEBHOOK_TOKEN no .env com pelo menos 32 caracteres aleatórios.');

            return self::FAILURE;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->components->error("A Pluggy só entrega webhooks em HTTPS público; confira APP_URL ({$url}).");

            return self::FAILURE;
        }

        // Pluggy keeps custom headers but never shows them, so the webhook is
        // always updated with the current token.
        $headers = ['X-Gamidas-Token' => $token];

        try {
            $existing = collect($pluggy->webhooks())->first(fn (array $webhook) => ($webhook['url'] ?? null) === $url);

            if ($existing !== null && is_string($existing['id'] ?? null)) {
                $pluggy->updateWebhook($existing['id'], ['event' => 'all', 'headers' => $headers, 'enabled' => true]);
                $this->components->info("Webhook atualizado: {$url}");
            } else {
                $pluggy->createWebhook($url, 'all', $headers);
                $this->components->info("Webhook registrado: {$url}");
            }
        } catch (PluggyException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
