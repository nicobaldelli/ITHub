<?php

declare(strict_types=1);

namespace ITHub\Api\Controllers;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\ConfigApp;
use ITHub\Api\Models\User;
use ITHub\Api\Services\AuditoriaService;
use ITHub\Api\Support\ResponseFactory;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lectura y edición de la tabla config_app (key-value runtime).
 *
 * Solo admin. Devuelve los valores tipados (string/int/bool/json) tal
 * como el modelo los castea. Al editar valida que el valor sea coherente
 * con el `tipo` declarado en la columna.
 */
final class ConfigController
{
    private readonly AuditoriaService $audit;

    public function __construct(private readonly ContainerInterface $container)
    {
        $this->container->get(Capsule::class);
        $this->audit = $this->container->get(AuditoriaService::class);
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $rows = ConfigApp::query()->orderBy('clave')->get();
        $payload = $rows->map(fn (ConfigApp $c) => [
            'clave' => $c->clave,
            'valor' => $c->valor,
            'value_parsed' => $c->value, // ya tipado por el modelo
            'tipo' => $c->tipo,
            'descripcion' => $c->descripcion,
            'updated_by' => $c->updated_by,
            'updated_at' => $c->updated_at?->format('c'),
        ])->all();

        return ResponseFactory::json($response, $payload);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        /** @var User $actor */
        $actor = $request->getAttribute('user');
        $clave = (string) $args['clave'];
        $body = (array) $request->getParsedBody();

        $cfg = ConfigApp::where('clave', $clave)->first();
        if ($cfg === null) {
            throw new NotFoundException('Clave de configuración no encontrada');
        }

        if (!array_key_exists('valor', $body)) {
            throw new ValidationException('Campo `valor` requerido', ['valor' => 'requerido']);
        }

        $raw = $body['valor'];
        $valor = $this->validarYSerializar($raw, $cfg->tipo);

        $before = ['valor' => $cfg->valor];
        $cfg->valor = $valor;
        $cfg->updated_by = $actor->id;
        $cfg->save();

        $this->audit->log(
            $actor->id,
            'config_app',
            null,
            Auditoria::ACCION_CONFIG_ACTUALIZADA,
            ['clave' => $clave, 'before' => $before['valor'], 'after' => $valor],
            $request
        );

        return ResponseFactory::json($response, [
            'clave' => $cfg->clave,
            'valor' => $cfg->valor,
            'value_parsed' => $cfg->value,
            'tipo' => $cfg->tipo,
            'descripcion' => $cfg->descripcion,
            'updated_by' => $cfg->updated_by,
            'updated_at' => $cfg->updated_at?->format('c'),
        ]);
    }

    private function validarYSerializar(mixed $raw, string $tipo): ?string
    {
        return match ($tipo) {
            'int' => $this->validarInt($raw),
            'bool' => $this->validarBool($raw),
            'json' => $this->validarJson($raw),
            default => $this->validarString($raw),
        };
    }

    private function validarInt(mixed $raw): string
    {
        if ($raw === null || $raw === '') {
            throw new ValidationException('Valor numérico requerido', ['valor' => 'requerido']);
        }
        if (!is_numeric($raw)) {
            throw new ValidationException('Debe ser un entero', ['valor' => 'no numérico']);
        }
        return (string) (int) $raw;
    }

    private function validarBool(mixed $raw): string
    {
        $b = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($b === null) {
            throw new ValidationException('Debe ser true o false', ['valor' => 'bool inválido']);
        }
        return $b ? '1' : '0';
    }

    private function validarJson(mixed $raw): string
    {
        // Aceptamos JSON ya stringificado o un array/objeto
        if (is_array($raw)) {
            return json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_string($raw)) {
            json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ValidationException('JSON inválido', ['valor' => json_last_error_msg()]);
            }
            return $raw;
        }
        throw new ValidationException('Debe ser JSON válido', ['valor' => 'tipo no soportado']);
    }

    private function validarString(mixed $raw): ?string
    {
        if ($raw === null) return null;
        if (!is_string($raw)) {
            return (string) $raw;
        }
        if (mb_strlen($raw) > 65535) {
            throw new ValidationException('Demasiado largo', ['valor' => 'max 65535']);
        }
        // Anti-script básico
        if (preg_match('/<\s*(script|iframe|object|embed)\b/i', $raw)) {
            throw new ValidationException('Contenido no permitido', ['valor' => 'tags bloqueados']);
        }
        return $raw;
    }
}
