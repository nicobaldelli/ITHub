<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * importe_total_pesos pasa a admitir NULL.
 *
 * Las facturas en USD que genera el cron de facturación automática no tienen
 * total en pesos hasta que el admin carga el TDC al marcarlas como enviadas.
 * El servicio ya insertaba NULL para ese caso, pero la columna era NOT NULL
 * DEFAULT 0 y MySQL rechazaba el INSERT: la cuota quedaba pendiente para
 * siempre. NULL = "pendiente de TDC"; 0 sigue siendo un total real de cero.
 */
final class FacturasImporteTotalPesosNullable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'MODIFY importe_total_pesos DECIMAL(15,2) NULL DEFAULT NULL'
        );
    }

    public function down(): void
    {
        $this->execute('UPDATE facturas_venta SET importe_total_pesos = 0 WHERE importe_total_pesos IS NULL');
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'MODIFY importe_total_pesos DECIMAL(15,2) NOT NULL DEFAULT 0'
        );
    }
}
