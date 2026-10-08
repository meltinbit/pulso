<?php

namespace App\Exceptions;

use RuntimeException;

class AdSenseApiException extends RuntimeException
{
    public function __construct(string $message = 'Errore nella comunicazione con Google AdSense.')
    {
        parent::__construct($message);
    }
}
