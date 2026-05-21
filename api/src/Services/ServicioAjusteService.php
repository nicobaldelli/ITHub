<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioAjuste;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Models\User;
use ITHub\Api\Validators\ServicioAjusteValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Gestiona el ciclo de vida de los ajustes de tarifa de un servicio.
 *
 * Workflow:
 *  1) create(): valida + calcula importes (modo monto ↔ porcentaje) +
 *     resuelve cuota_desde_id (si no viene, usa la primera cuota pendiente
 *     con fecha_prevista >= fecha_aplicacion) + persiste en aplicado=false.
 *     Si tipo='espontaneo' y fecha_aplicacion <= hoy → aplica automáticamente.
 *  2) aplicar(): efectiviza el ajuste — actualiza importe_base del servicio
 *     + actualiza importes de cuotas pendientes >= cuota_desde_id +
 *     marca como aplicado=true.
 *
 * Solo se aplica a mantenimientos.
 * Las cuotas facturadas / omitidas / canceladas NUNCA se tocan.
 */
final class ServicioAjusteService
{
    public function __construct(
        private readonly AuditoriaService $audit
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public function create(
        int $servicioId,
        array $data,
        User $user,
        ServerRequestInterface $request
    ): ServicioAjuste {
        $servicio = Servicio::find($servicioId);
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }
        if (!$servicio->esMantenimiento()) {
            throw new ValidationException(
                'Solo los servicios de mantenimiento aceptan ajustes',
                ['tipo' => 'solo mantenimiento']
            );
        }
        if ($servicio->estado !== Servicio::ESTADO_ACTIVO) {
            throw new ValidationException(
                'El servicio no está activo',
                ['estado' => $servicio->estado]
            );
        }

        $clean = ServicioAjusteValidator::validateCreate($data);

        $importeAnterior = (float) $servicio->importe_base;

        // Calcular importe_nuevo y porcentaje_variacion
        if ($clean['modo'] === 'monto') {
            $importeNuevo = $clean['valor'];
            $porcVar = $importeAnterior > 0
                ? round(($importeNuevo - $importeAnterior) / $importeAnterior * 100, 4)
                : 0.0;
        } else {
            $porcVar = $clean['valor'];
            $importeNuevo = round($importeAnterior * (1 + $porcVar / 100), 2);
            if ($importeNuevo <= 0) {
                throw new ValidationException(
                    'El porcentaje aplicado deja el importe en 0 o negativo',
                    ['valor' => 'porcentaje muy negativo']
                );
            }
        }

        // Resolver cuota_desde_id
        $cuotaDesdeId = $clean['cuota_desde_id'];
        if ($cuotaDesdeId === null) {
            // Buscamos la primera cuota pendiente con fecha_prevista >= fecha_aplicacion
            $primera = ServicioCuota::where('servicio_id', $servicio->id)
                ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
                ->where('fecha_prevista', '>=', $clean['fecha_aplicacion'])
                ->orderBy('numero_cuota')
                ->first();
            if ($primera === null) {
                throw new ValidationException(
                    'No hay cuotas pendientes a partir de la fecha de aplicación',
                    ['fecha_aplicacion' => 'sin cuotas pendientes']
                );
            }
            $cuotaDesdeId = $primera->id;
        } else {
            $cuota = ServicioCuota::find($cuotaDesdeId);
            if ($cuota === null || $cuota->servicio_id !== $servicio->id) {
                throw new ValidationException(
                    'La cuota no pertenece a este servicio',
                    ['cuota_desde_id' => 'no encontrada o no pertenece']
                );
            }
            if ($cuota->estado !== ServicioCuota::ESTADO_PENDIENTE) {
                throw new ValidationException(
                    'La cuota desde la cual aplicar el ajuste debe estar pendiente',
                    ['cuota_desde_id' => 'no está pendiente']
                );
            }
        }

        $ajuste = ServicioAjuste::create([
            'servicio_id' => $servicio->id,
            'tipo' => $clean['tipo'],
            'fecha_aplicacion' => $clean['fecha_aplicacion'],
            'cuota_desde_id' => $cuotaDesdeId,
            'importe_anterior' => $importeAnterior,
            'importe_nuevo' => $importeNuevo,
            'porcentaje_variacion' => $porcVar,
            'aplicado' => false,
            'observaciones' => $clean['observaciones'],
            'created_by' => $user->id,
        ]);

        $this->audit->log(
            $user->id,
            'servicio_ajuste',
            $ajuste->id,
            Auditoria::ACCION_CREAR,
            [
                'tipo' => $ajuste->tipo,
                'importe_anterior' => $importeAnterior,
                'importe_nuevo' => $importeNuevo,
                'porcentaje_variacion' => $porcVar,
                'cuota_desde_id' => $cuotaDesdeId,
            ],
            $request
        );

        // Si es espontáneo y la fecha_aplicacion ya pasó → aplicar inmediatamente
        if ($clean['tipo'] === ServicioAjuste::TIPO_ESPONTANEO
            && strtotime($clean['fecha_aplicacion']) <= time()) {
            $ajuste = $this->aplicar($ajuste->id, $user, $request);
        }

        return $ajuste->fresh();
    }

    public function aplicar(int $ajusteId, User $user, ServerRequestInterface $request): ServicioAjuste
    {
        $ajuste = ServicioAjuste::find($ajusteId);
        if ($ajuste === null) {
            throw new NotFoundException('Ajuste no encontrado');
        }
        if ($ajuste->aplicado) {
            throw new ValidationException(
                'El ajuste ya fue aplicado',
                ['aplicado_at' => $ajuste->aplicado_at?->format('c')]
            );
        }

        $servicio = Servicio::find($ajuste->servicio_id);
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }
        if ($servicio->estado !== Servicio::ESTADO_ACTIVO) {
            throw new ValidationException(
                'El servicio no está activo',
                ['estado' => $servicio->estado]
            );
        }

        Capsule::connection()->transaction(function () use ($ajuste, $servicio, $user, $request) {
            // Actualizar importe_base del servicio
            $servicio->importe_base = (float) $ajuste->importe_nuevo;
            $servicio->updated_by = $user->id;
            $servicio->save();

            // Actualizar cuotas pendientes >= cuota_desde_id
            // (las facturadas/omitidas/canceladas no se tocan — confirmado)
            $cuotaDesde = ServicioCuota::find($ajuste->cuota_desde_id);
            $query = ServicioCuota::where('servicio_id', $servicio->id)
                ->where('estado', ServicioCuota::ESTADO_PENDIENTE);

            if ($cuotaDesde !== null) {
                $query->where('numero_cuota', '>=', $cuotaDesde->numero_cuota);
            }

            $cuotasAfectadas = $query->get();
            foreach ($cuotasAfectadas as $c) {
                // Cuotas proporcionales escalan también: importe = nuevo_base * dias_cubiertos / intervalo
                // Para mes_calendario no tenemos dias_cubiertos en cuotas normales (solo en la última proporcional)
                if ($c->es_proporcional && $c->dias_cubiertos !== null) {
                    // Asumimos que el intervalo "normal" es 30 días para mes_calendario, o intervalo_dias para el otro modo
                    $intervaloRef = $servicio->modo_facturacion === Servicio::MODO_INTERVALO_DIAS
                        ? (int) $servicio->intervalo_dias
                        : 30;
                    $c->importe = round((float) $ajuste->importe_nuevo * $c->dias_cubiertos / max(1, $intervaloRef), 2);
                } else {
                    $c->importe = (float) $ajuste->importe_nuevo;
                }
                $c->save();
            }

            $ajuste->aplicado = true;
            $ajuste->aplicado_at = date('Y-m-d H:i:s');
            $ajuste->aplicado_por = $user->id;
            $ajuste->save();

            $this->audit->log(
                $user->id,
                'servicio_ajuste',
                $ajuste->id,
                Auditoria::ACCION_EDITAR,
                [
                    'accion' => 'aplicado',
                    'cuotas_afectadas' => count($cuotasAfectadas),
                    'importe_nuevo' => (float) $ajuste->importe_nuevo,
                ],
                $request
            );
        });

        return $ajuste->fresh();
    }

    /**
     * Devuelve los ajustes de un servicio (ordenados por fecha de aplicación desc).
     */
    public function listar(int $servicioId): array
    {
        return ServicioAjuste::where('servicio_id', $servicioId)
            ->orderByDesc('fecha_aplicacion')
            ->orderByDesc('id')
            ->get()
            ->toArray();
    }

    public function eliminar(int $ajusteId, User $user, ServerRequestInterface $request): void
    {
        $ajuste = ServicioAjuste::find($ajusteId);
        if ($ajuste === null) {
            throw new NotFoundException('Ajuste no encontrado');
        }
        if ($ajuste->aplicado) {
            throw new ValidationException(
                'No se puede eliminar un ajuste ya aplicado (afectaría auditoría)',
                ['aplicado_at' => $ajuste->aplicado_at?->format('c')]
            );
        }
        $ajuste->delete();

        $this->audit->log(
            $user->id,
            'servicio_ajuste',
            $ajusteId,
            Auditoria::ACCION_ELIMINAR,
            ['estado_previo' => 'no_aplicado'],
            $request
        );
    }
}
