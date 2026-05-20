<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class HttpUnsupportedMediaException extends AppException
{
    public function __construct(string $message = 'Tipo de contenido no soportado')
    {
        parent::__construct($message, 415, 'UNSUPPORTED_MEDIA_TYPE');
    }
}
