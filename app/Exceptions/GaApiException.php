<?php

namespace App\Exceptions;

use RuntimeException;

class GaApiException extends RuntimeException
{
    public function __construct(string $message = 'Errore nella comunicazione con Google Analytics.')
    {
        parent::__construct($message);
    }
}
