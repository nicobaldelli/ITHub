<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use ITHub\Api\Models\Auditoria;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Registra eventos en la tabla `auditoria`.
 * Cada acción sensible debería llamar a `log(...)`.
 */
final class AuditoriaService
{
    /**
     * @param array<string,mixed> $camposModificados Diff before/after o payload del evento
     */
    public function log(
        ?int $userId,
        string $entidad,
        ?int $entidadId,
        string $accion,
        array $camposModificados = [],
        ?ServerRequestInterface $request = null
    ): Auditoria {
        return Auditoria::create([
            'user_id' => $userId,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'accion' => $accion,
            'campos_modificados' => $camposModificados,
            'ip' => $request ? $this->clientIp($request) : null,
            'user_agent' => $request ? mb_substr($request->getHeaderLine('User-Agent'), 0, 255) : null,
            'request_id' => $request ? (string) $request->getAttribute('request_id', '') : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Shortcut para acciones del sistema (cron, jobs en background)
     * que no tienen un user ni un request asociado.
     *
     * @param array<string,mixed> $camposModificados
     */
    public function logSystem(
        string $entidad,
        ?int $entidadId,
        string $accion,
        array $camposModificados = []
    ): Auditoria {
        return Auditoria::create([
            'user_id' => null,
            'entidad' => $entidad,
            'entidad_id' => $entidadId,
            'accion' => $accion,
            'campos_modificados' => $camposModificados,
            'ip' => null,
            'user_agent' => 'system',
            'request_id' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function clientIp(ServerRequestInterface $request): string
    {
        $forwarded = $request->getHeaderLine('X-Forwarded-For');
        if ($forwarded !== '') {
            $first = trim(explode(',', $forwarded)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }
        $server = $request->getServerParams();
        return $server['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
