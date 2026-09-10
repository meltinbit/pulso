<?php

namespace App\Exceptions;

use RuntimeException;

class SearchConsoleApiException extends RuntimeException
{
    public function __construct(string $message = 'Errore nella comunicazione con Google Search Console.')
    {
        parent::__construct($message);
    }
}
