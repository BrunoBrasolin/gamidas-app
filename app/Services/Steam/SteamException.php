<?php

namespace App\Services\Steam;

use RuntimeException;

/**
 * A failed call to the Steam Web API, with a message fit for the page. It
 * never carries the API key or the request URL (which holds the key).
 */
class SteamException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('Steam não configurada: defina STEAM_API_KEY no .env do servidor.');
    }

    public static function privateProfile(): self
    {
        return new self('O perfil da Steam é privado. Em Perfil › Editar perfil › Privacidade, deixe "Detalhes do jogo" como Público.', 403);
    }
}
