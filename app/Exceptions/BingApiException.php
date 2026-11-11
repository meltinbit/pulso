<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Bing Webmaster Tools API failure translated into a readable message.
 */
class BingApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Errore nella comunicazione con Bing Webmaster Tools.',
        public readonly bool $isAuthError = false,
    ) {
        parent::__construct($message);
    }
}
