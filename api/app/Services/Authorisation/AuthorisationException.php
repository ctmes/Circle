<?php

namespace App\Services\Authorisation;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AuthorisationException extends HttpException
{
    public function __construct(public readonly AccessDecision $decision)
    {
        parent::__construct(403, $decision->message);
    }
}
