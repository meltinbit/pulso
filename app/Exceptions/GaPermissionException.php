<?php

namespace App\Exceptions;

use RuntimeException;

class GaPermissionException extends RuntimeException
{
    public function __construct(string $message = 'Permessi insufficienti per accedere a questa property Google Analytics.')
    {
        parent::__construct($message);
    }
}
