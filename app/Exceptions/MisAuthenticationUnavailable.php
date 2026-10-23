<?php

namespace App\Exceptions;

use RuntimeException;

class MisAuthenticationUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Employee sign-in is temporarily unavailable. Please try again later.');
    }
}
