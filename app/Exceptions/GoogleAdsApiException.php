<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A Google Ads API failure translated into a readable message.
 */
class GoogleAdsApiException extends RuntimeException
{
    public function __construct(
        string $message = 'Errore nella comunicazione con Google Ads API.',
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}
