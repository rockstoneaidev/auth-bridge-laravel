<?php

declare(strict_types=1);

namespace AuthBridge\Laravel\Exceptions;

use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class InvalidTokenException extends UnauthorizedHttpException
{
    public function __construct(string $message = 'Invalid token', ?Throwable $previous = null, int $code = 0, array $headers = [])
    {
        parent::__construct('Bearer', $message, $previous, $code, $headers);
    }
}
