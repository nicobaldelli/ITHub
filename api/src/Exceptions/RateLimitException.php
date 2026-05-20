<?php

declare(strict_types=1);

namespace ITHub\Api\Exceptions;

final class RateLimitException extends AppException
{
    public function __construct(public readonly int $retryAfter, string $message = 'Demasiadas solicitudes. Probá más tarde.')
    {
        parent::__construct($message, 429, 'RATE_LIMITED', ['retry_after' => $retryAfter]);
    }
}
