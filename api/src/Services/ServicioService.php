<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Exceptions\NotFoundException;
use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\Auditoria;
use ITHub\Api\Models\Cliente;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Models\User;
use ITHub\Api\Repositories\ServicioRepository;
use ITHub\Api\Validators\ServicioValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lógica de negocio de servicios.
 *
 * Reglas clave aplicadas:
 *  - Creación: valida + genera cronograma + persiste todo en TRANSACCIÓN
 *  - Edición: solo permite cambiar campos "no críticos" salvo que no existan
 *    cuotas facturadas; si se cambia algo que afecta cronograma, se REGENERAN
 *    las cuotas pendientes (las facturadas/canceladas/omitidas quedan intactas)
 *  - Borrado: soft delete; bloqueado si hay cuotas facturadas
 *  - Audita TODO con before/after
 */
final class ServicioService
{
    /** Campos que, al cambiar, requieren regenerar el cronograma pendiente */
    private const CAMPOS_QUE_AFECTAN_CRONOGRAMA = [
        'importe_base', 'fecha_inicio', 'fecha_fin',
        'modo_facturacion', 'dia_facturacion', 'intervalo_dias',
    ];

    public function __construct(
        private readonly ServicioRepository $repo,
        private readonly AuditoriaService $audit
    ) {
    }

    /**
     * Crea el servicio y su cronograma de cuotas en una transacción.
     *
     * @param array<string,mixed> $data
     */
    public function create(array $data, User $user, ServerRequestInterface $request): Servicio
    {
        $payload = ServicioValidator::validateCreate($data);
        $servicioData = $payload['servicio'];
        $proyectoCuotas = $payload['cuotas']; // empty para mantenimiento

        // Cliente debe existir y estar activo
        $cliente = Cliente::find($servicioData['cliente_id']);
        if ($cliente === null) {
            throw new ValidationException('Cliente no encontrado', ['cliente_id' => 'no existe']);
        }
        if (!$cliente->activo) {
            throw new ValidationException('Cliente inactivo', ['cliente_id' => 'inactivo']);
        }

        $servicioData['created_by'] = $user->id;
        $servicioData['updated_by'] = $user->id;
        $servicioData['estado'] = Servicio::ESTADO_ACTIVO;

        return Capsule::connection()->transaction(function () use ($servicioData, $proyectoCuotas, $user, $request) {
            $servicio = Servicio::create($servicioData);

            // Generar cronograma usando el helper puro
            $cuotas = CronogramaGenerator::generar($servicio, $proyectoCuotas);

            if (empty($cuotas)) {
                throw new ValidationException(
                    'No se pudo generar el cronograma con los datos provistos',
                    ['cuotas' => 'el cronograma resultó vacío']
                );
            }

            foreach ($cuotas as $c) {
                ServicioCuota::create(array_merge($c, ['servicio_id' => $servicio->id]));
            }

            $this->audit->log(
                $user->id,
                'servicio',
                $servicio->id,
                Auditoria::ACCION_CREAR,
                [
                    'tipo' => $servicio->tipo,
                    'nombre' => $servicio->nombre,
                    'cliente_id' => $servicio->cliente_id,
                    'cuotas_generadas' => count($cuotas),
                ],
                $request
            );

            return $servicio->fresh(['cliente', 'cuotas']);
        });
    }

    /**
     * Edita un servicio.
     *
     * @param array<string,mixed> $data
     */
    public function update(int $id, array $data, User $user, ServerRequestInterface $request): Servicio
    {
        $servicio = $this->repo->findById($id);
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }

        $clean = ServicioValidator::validateUpdate($data);

        // ¿Algún campo afecta el cronograma?
        $afectanCronograma = array_intersect_key($clean, array_flip(self::CAMPOS_QUE_AFECTAN_CRONOGRAMA));
        $regenerar = false;
        foreach ($afectanCronograma as $f => $valor) {
            if ($valor != $servicio->{$f}) {
                $regenerar = true;
                break;
            }
        }

        if ($regenerar && $this->repo->tieneCuotasFacturadas($id)) {
            throw new ValidationException(
                'No se pueden cambiar campos que afectan el cronograma porque ya hay cuotas facturadas. ' .
                'Usá un ajuste de precio o creá un servicio nuevo.',
                ['cronograma' => 'cuotas ya facturadas']
            );
        }

        return Capsule::connection()->transaction(function () use ($servicio, $clean, $user, $request, $regenerar) {
            $before = $servicio->only(array_keys($clean));

            $servicio->fill($clean);
            $servicio->updated_by = $user->id;
            $servicio->save();

            if ($regenerar) {
                // Borramos las cuotas PENDIENTES y regeneramos.
                ServicioCuota::where('servicio_id', $servicio->id)
                    ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
                    ->delete();

                // El generador necesita las cuotas YA fijadas (proyectos con porcentajes)
                // Para mantenimientos las regeneramos desde cero.
                // Para proyectos no permitimos regenerar via update — el admin debe usar endpoint específico.
                if ($servicio->esMantenimiento()) {
                    $nuevas = CronogramaGenerator::generar($servicio);
                    // Numerar desde donde quedó la última facturada/cancelada
                    $maxNumero = (int) (ServicioCuota::where('servicio_id', $servicio->id)->max('numero_cuota') ?? 0);
                    foreach ($nuevas as $i => $c) {
                        $c['numero_cuota'] = $maxNumero + $i + 1;
                        $c['servicio_id'] = $servicio->id;
                        ServicioCuota::create($c);
                    }
                }
            }

            $this->audit->log(
                $user->id,
                'servicio',
                $servicio->id,
                Auditoria::ACCION_EDITAR,
                [
                    'before' => $before,
                    'after' => $servicio->only(array_keys($clean)),
                    'cronograma_regenerado' => $regenerar,
                ],
                $request
            );

            return $servicio->fresh(['cliente', 'cuotas']);
        });
    }

    public function delete(int $id, User $user, ServerRequestInterface $request): void
    {
        $servicio = $this->repo->findById($id);
        if ($servicio === null) {
            throw new NotFoundException('Servicio no encontrado');
        }

        if ($this->repo->tieneCuotasFacturadas($id)) {
            throw new ValidationException(
                'No se puede eliminar: el servicio tiene cuotas facturadas. Cancelalo en su lugar.',
                ['servicio_id' => 'tiene cuotas facturadas']
            );
        }

        Capsule::connection()->transaction(function () use ($servicio, $user, $request) {
            // Cancelar todas las cuotas pendientes
            ServicioCuota::where('servicio_id', $servicio->id)
                ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
                ->update(['estado' => ServicioCuota::ESTADO_CANCELADA]);

            $servicio->delete(); // soft delete

            $this->audit->log(
                $user->id,
                'servicio',
                $servicio->id,
                Auditoria::ACCION_ELIMINAR,
                ['soft_delete' => true, 'nombre' => $servicio->nombre],
                $request
            );
        });
    }
}
