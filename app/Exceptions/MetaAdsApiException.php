<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Meta Marketing API failure translated into a readable message.
 */
class MetaAdsApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Errore nella comunicazione con la Marketing API di Meta.',
        public readonly bool $isAuthError = false,
    ) {
        parent::__construct($message);
    }
}
