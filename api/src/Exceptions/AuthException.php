<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class AuthException extends AppException
{
    public function __construct(string $message = 'No autenticado', string $code = 'UNAUTHENTICATED')
    {
        parent::__construct($message, 401, $code);
    }
}
