<?php

namespace App\Exceptions;

use RuntimeException;

class GoogleTokenExpiredException extends RuntimeException
{
    public function __construct(string $message = 'Connessione Google scaduta. Ricollega il tuo account.')
    {
        parent::__construct($message);
    }
}
