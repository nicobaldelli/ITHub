<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class NotFoundException extends AppException
{
    public function __construct(string $message = 'Recurso no encontrado')
    {
        parent::__construct($message, 404, 'NOT_FOUND');
    }
}
