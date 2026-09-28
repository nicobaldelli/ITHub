<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Support;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

/**
 * Container PSR-11 mínimo para tests unitarios de servicios que solo
 * necesitan `settings` (JwtService, por ejemplo). Evita levantar PHP-DI.
 */
final class ArrayContainer implements ContainerInterface
{
    /** @param array<string,mixed> $entries */
    public function __construct(private array $entries)
    {
    }

    public function get(string $id): mixed
    {
        if (!array_key_exists($id, $this->entries)) {
            throw new class ("No hay entrada '{$id}' en el container de test") extends \RuntimeException implements NotFoundExceptionInterface {
            };
        }
        return $this->entries[$id];
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->entries);
    }
}
