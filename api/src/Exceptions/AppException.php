<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base de excepciones controladas de la app.
 * Cualquier excepción que herede de esta clase es "safe to expose" al cliente
 * (mensaje sin info sensible, status HTTP intencional).
 */
class AppException extends RuntimeException
{
    public function __construct(
        string $message,
        protected int $statusCode = 400,
        protected string $errorCode = 'APP_ERROR',
        protected array $details = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
