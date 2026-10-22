<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use ITHub\Api\Exceptions\ValidationException;
use ITHub\Api\Models\ServicioCuota;
use ITHub\Api\Models\User;
use ITHub\Api\Services\ServicioService;
use ITHub\Api\Tests\Support\DatabaseTestCase;

/**
 * Update de servicios contra la base: coherencia de fechas con lo persistido
 * (el validador solo compara cuando vienen las dos) y regeneración de cuotas.
 */
final class ServicioServiceTest extends DatabaseTestCase
{
    private ServicioService $service;
    private User $user;
    private int $clienteId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->container->get(ServicioService::class);
        $this->user = User::find($this->crearUsuario());
        $this->clienteId = $this->crearCliente();
    }

    /** @return array<string,string> detalles de la ValidationException */
    private function detallesUpdate(int $id, array $data): array
    {
        try {
            $this->service->update($id, $data, $this->user, $this->makeRequest());
        } catch (ValidationException $e) {
            return $e->getDetails();
        }
        self::fail('Se esperaba ValidationException');
    }

    public function testNoDejaPonerUnaFechaFinAnteriorALaFechaInicioPersistida(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-12-01']);

        $details = $this->detallesUpdate($id, ['fecha_fin' => '2026-05-31']);
        self::assertStringStartsWith('Debe ser posterior a fecha_inicio', $details['fecha_fin']);

        $details = $this->detallesUpdate($id, ['fecha_fin' => '2026-06-01']);
        self::assertStringStartsWith('Debe ser posterior', $details['fecha_fin']);
    }

    public function testNoDejaMoverLaFechaInicioMasAllaDeLaFechaFinPersistida(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-12-01']);

        $details = $this->detallesUpdate($id, ['fecha_inicio' => '2026-12-15']);
        self::assertArrayHasKey('fecha_fin', $details);
    }

    public function testUnProyectoNoPuedeQuedarSinFechaFin(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id, [
            'tipo' => 'proyecto',
            'modo_facturacion' => null,
            'dia_facturacion' => null,
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-12-01',
        ]);
        $this->crearCuota($id, 1, '2026-06-01', ['porcentaje' => 100, 'total_cuotas' => 1]);

        $details = $this->detallesUpdate($id, ['fecha_fin' => null]);
        self::assertSame('Requerida para proyectos', $details['fecha_fin']);
    }

    public function testUnMantenimientoSiPuedeVolverAIndefinido(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id, ['fecha_inicio' => '2026-06-01', 'fecha_fin' => '2026-12-01']);

        $servicio = $this->service->update($id, ['fecha_fin' => null], $this->user, $this->makeRequest());

        self::assertNull($servicio->fecha_fin);
    }

    public function testCambiarFechaFinRegeneraElCronogramaPendiente(): void
    {
        // Mantenimiento mensual el día 1, de junio a septiembre: 4 cuotas pendientes
        $id = $this->crearServicio($this->clienteId, $this->user->id, [
            'fecha_inicio' => '2026-06-01',
            'fecha_fin' => '2026-10-01',
            'dia_facturacion' => 1,
        ]);
        $viejas = [];
        foreach (['2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01'] as $i => $f) {
            $viejas[] = $this->crearCuota($id, $i + 1, $f);
        }

        // Extender hasta fin de año: se borran las pendientes y se regeneran
        $this->service->update($id, ['fecha_fin' => '2027-01-01'], $this->user, $this->makeRequest());

        $cuotas = ServicioCuota::where('servicio_id', $id)->orderBy('numero_cuota')->get();
        // junio..diciembre = 7 (fecha_fin 2027-01-01 coincide con una cuota y se descarta)
        self::assertCount(7, $cuotas);
        self::assertSame(range(1, 7), $cuotas->pluck('numero_cuota')->all());
        self::assertSame('2026-06-01', $cuotas[0]->fecha_prevista->format('Y-m-d'));
        self::assertSame('2026-12-01', $cuotas[6]->fecha_prevista->format('Y-m-d'));
        self::assertSame(7, $cuotas->where('estado', 'pendiente')->count());
        self::assertSame(7, $cuotas->where('total_cuotas', 7)->count());
        self::assertEmpty(array_intersect($viejas, $cuotas->pluck('id')->all()), 'las viejas se reemplazaron');
    }

    public function testNoDejaCambiarElCronogramaSiYaHayCuotasFacturadas(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id, ['importe_base' => 1000]);
        $this->crearCuota($id, 1, '2026-06-01', ['estado' => 'facturada']);

        $details = $this->detallesUpdate($id, ['importe_base' => 2000]);
        self::assertArrayHasKey('cronograma', $details);
    }

    public function testUpdateDeCamposQueNoAfectanElCronogramaNoTocaLasCuotas(): void
    {
        $id = $this->crearServicio($this->clienteId, $this->user->id);
        $c1 = $this->crearCuota($id, 1, '2026-06-01');
        $c2 = $this->crearCuota($id, 2, '2026-07-01');

        $servicio = $this->service->update($id, ['nombre' => 'Otro nombre', 'observaciones' => 'x'], $this->user, $this->makeRequest());

        self::assertSame('Otro nombre', $servicio->nombre);
        self::assertSame([$c1, $c2], ServicioCuota::where('servicio_id', $id)->orderBy('id')->pluck('id')->all());
    }
}
