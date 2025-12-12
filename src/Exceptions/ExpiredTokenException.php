<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class ExpiredTokenException extends UnauthorizedHttpException
{
    public function __construct(string $message = 'Token expired', ?Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct('Bearer', $message, $previous, $code, $headers);
    }
}
