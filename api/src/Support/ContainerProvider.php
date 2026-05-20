<?php

declare(strict_types=1);

namespace ITHub\Api\Support;

use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Acceso al container desde middlewares instanciados manualmente.
 * Se setea una sola vez en App::build.
 */
final class ContainerProvider
{
    private static ?ContainerInterface $container = null;

    public static function set(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    public static function get(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('ContainerProvider no inicializado');
        }
        return self::$container;
    }
}
