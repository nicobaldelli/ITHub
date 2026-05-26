<?php

declare(strict_types=1);

namespace ITHub\Api\Services;

use Illuminate\Database\Capsule\Manager as Capsule;
use ITHub\Api\Models\Servicio;
use ITHub\Api\Models\ServicioCuota;
use Psr\Log\LoggerInterface;

/**
 * Mantiene siempre N cuotas pendientes futuras para mantenimientos
 * indefinidos (sin fecha_fin).
 *
 * El cron mensual llama a `extend()`. Para cada servicio indefinido activo:
 *  - cuenta cuántas cuotas pendientes con fecha_prevista >= hoy hay
 *  - si son menos de MIN_FUTURAS, genera nuevas hasta completar TARGET_FUTURAS
 *
 * Reusa CronogramaGenerator construyendo un Servicio "virtual" cuya
 * fecha_inicio es el día siguiente a la última fecha generada del cronograma.
 */
final class RollingWindowService
{
    /**
     * Si quedan menos cuotas pendientes futuras que esto, se extiende.
     * El valor está calibrado para no extender en cada corrida si no hace falta.
     */
    public const MIN_FUTURAS = 4;

    /**
     * Cantidad objetivo de cuotas pendientes futuras tras la extensión.
     */
    public const TARGET_FUTURAS = 12;

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Corre el rolling window para todos los servicios indefinidos activos.
     * @return array{servicios_revisados:int, servicios_extendidos:int, cuotas_creadas:int}
     */
    public function extend(): array
    {
        $resumen = ['servicios_revisados' => 0, 'servicios_extendidos' => 0, 'cuotas_creadas' => 0];
        $hoy = date('Y-m-d');

        $servicios = Servicio::query()
            ->where('tipo', Servicio::TIPO_MANTENIMIENTO)
            ->where('estado', Servicio::ESTADO_ACTIVO)
            ->whereNull('fecha_fin') // solo indefinidos
            ->whereNull('deleted_at')
            ->get();

        foreach ($servicios as $servicio) {
            $resumen['servicios_revisados']++;

            $futuras = ServicioCuota::where('servicio_id', $servicio->id)
                ->where('estado', ServicioCuota::ESTADO_PENDIENTE)
                ->where('fecha_prevista', '>=', $hoy)
                ->count();

            if ($futuras >= self::MIN_FUTURAS) {
                continue;
            }

            $cuotasACrear = self::TARGET_FUTURAS - $futuras;
            $creadas = $this->extenderServicio($servicio, $cuotasACrear);
            if ($creadas > 0) {
                $resumen['servicios_extendidos']++;
                $resumen['cuotas_creadas'] += $creadas;
            }
        }

        return $resumen;
    }

    /**
     * Genera `cantidad` cuotas adicionales para un servicio indefinido.
     * @return int cantidad efectivamente creada
     */
    private function extenderServicio(Servicio $servicio, int $cantidad): int
    {
        // Última fecha del cronograma (cualquier estado) para arrancar desde el día siguiente
        $ultimaFecha = ServicioCuota::where('servicio_id', $servicio->id)->max('fecha_prevista');
        $ultimoNumero = (int) (ServicioCuota::where('servicio_id', $servicio->id)->max('numero_cuota') ?? 0);

        // Construimos un servicio virtual con fecha_inicio = día siguiente al último.
        // Si no había cuotas previas (caso borde), usamos fecha_inicio del servicio + 1 día.
        $virtual = clone $servicio;
        if ($ultimaFecha) {
            $virtual->fecha_inicio = date('Y-m-d', strtotime($ultimaFecha . ' + 1 day'));
        }
        // Forzamos indefinido virtual (ya lo era)
        $virtual->fecha_fin = null;

        // Pedimos al generator más meses que cantidad para garantizar que en intervalo_dias
        // alcance; el límite real lo hacemos cortando abajo
        $windowMonths = max($cantidad, 1);
        $nuevas = CronogramaGenerator::generar($virtual, [], $windowMonths);

        // Cortamos al exacto requerido
        $nuevas = array_slice($nuevas, 0, $cantidad);

        if (empty($nuevas)) {
            return 0;
        }

        $created = 0;
        Capsule::connection()->transaction(function () use ($nuevas, $servicio, $ultimoNumero, &$created): void {
            foreach ($nuevas as $i => $c) {
                $c['numero_cuota'] = $ultimoNumero + $i + 1;
                $c['servicio_id'] = $servicio->id;
                ServicioCuota::create($c);
                $created++;
            }
        });

        $this->logger->info('rolling_window.extendido', [
            'servicio_id' => $servicio->id,
            'creadas' => $created,
        ]);

        return $created;
    }
}
