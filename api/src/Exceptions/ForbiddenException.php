<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class ForbiddenException extends AppException
{
    public function __construct(string $message = 'No tenés permisos para realizar esta acción')
    {
        parent::__construct($message, 403, 'FORBIDDEN');
    }
}
