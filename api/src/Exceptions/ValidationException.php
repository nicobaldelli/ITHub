<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class ValidationException extends AppException
{
    public function __construct(string $message = 'Datos inválidos', array $details = [])
    {
        parent::__construct($message, 422, 'VALIDATION_ERROR', $details);
    }
}
