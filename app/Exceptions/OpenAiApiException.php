<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An OpenAI API failure translated into a readable message.
 */
class OpenAiApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Errore nella comunicazione con OpenAI.',
        public readonly bool $isAuthError = false,
    ) {
        parent::__construct($message);
    }
}
