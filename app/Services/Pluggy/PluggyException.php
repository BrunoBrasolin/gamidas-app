<?php

namespace App\Services\Pluggy;

use RuntimeException;

/**
 * A failed call to the Pluggy API. The message never carries financial data;
 * the code is Pluggy's codeDescription (e.g. ITEM_NOT_FOUND) when it sent one.
 */
class PluggyException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('Pluggy não configurado: defina PLUGGY_CLIENT_ID e PLUGGY_CLIENT_SECRET no .env.');
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
