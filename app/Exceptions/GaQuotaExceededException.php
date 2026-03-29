<?php

namespace App\Exceptions;

use RuntimeException;

class GaQuotaExceededException extends RuntimeException
{
    public function __construct(string $message = 'Quota API Google Analytics superata. Riprova tra qualche minuto.')
    {
        parent::__construct($message);
    }
}
