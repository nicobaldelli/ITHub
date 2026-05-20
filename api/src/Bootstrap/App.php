<?php

declare(strict_types=1);

namespace ITHub\Api\Bootstrap;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use ITHub\Api\Support\ContainerProvider;
use Slim\App as SlimApp;
use Slim\Factory\AppFactory;

/**
 * Bootstrap principal de la aplicación.
 *
 * Responsabilidades:
 *  - Cargar .env y validar variables obligatorias
 *  - Configurar timezone, error reporting según entorno
 *  - Construir el container DI
 *  - Registrar middlewares globales (orden crítico)
 *  - Registrar rutas
 */
final class App
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function build(): SlimApp
    {
        // 1. Cargar variables de entorno
        $this->loadEnv();

        // 2. Cargar settings
        $settings = require $this->basePath . '/config/settings.php';

        // 3. Configurar timezone
        date_default_timezone_set($settings['app']['timezone']);

        // 4. Configurar error reporting (NUNCA mostrar errores en producción)
        $this->configureErrorReporting($settings['app']['is_production'], $settings['app']['debug']);

        // 5. Construir container DI
        $container = $this->buildContainer($settings);
        ContainerProvider::set($container);

        // 6. Crear app Slim
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->setBasePath('/api/v1');

        // 7. Registrar middlewares globales (orden importa: el último agregado es el primero en ejecutar)
        Middleware::register($app, $settings);

        // 8. Registrar rutas
        Routes::register($app);

        return $app;
    }

    private function loadEnv(): void
    {
        if (file_exists($this->basePath . '/.env')) {
            $dotenv = Dotenv::createImmutable($this->basePath);
            $dotenv->load();
        }
    }

    private function configureErrorReporting(bool $isProduction, bool $debug): void
    {
        if ($isProduction) {
            error_reporting(E_ALL);
            ini_set('display_errors', '0');
            ini_set('display_startup_errors', '0');
            ini_set('log_errors', '1');
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', $debug ? '1' : '0');
        }
        ini_set('expose_php', '0');
    }

    private function buildContainer(array $settings): \Psr\Container\ContainerInterface
    {
        $builder = new ContainerBuilder();

        if ($settings['app']['is_production']) {
            $cacheDir = $this->basePath . '/' . $settings['storage']['cache'];
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0750, true);
            }
            $builder->enableCompilation($cacheDir);
        }

        $definitions = require $this->basePath . '/config/container.php';
        $definitions['settings'] = $settings;
        $definitions['basePath'] = $this->basePath;

        $builder->addDefinitions($definitions);

        return $builder->build();
    }
}
