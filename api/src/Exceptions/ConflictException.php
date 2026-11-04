<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

/** 409: la operación choca con un estado actual (ej: una tarea ya corriendo). */
final class ConflictException extends AppException
{
    public function __construct(string $message = 'Conflicto con el estado actual', string $errorCode = 'CONFLICT')
    {
        parent::__construct($message, 409, $errorCode);
    }
}
