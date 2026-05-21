<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Agrega servicio_cuota_id a facturas_venta para vincular cada factura a una cuota
 * del cronograma de un servicio. NULL = factura suelta (no asociada a servicio).
 *
 * La FK es SET NULL para que si se borra una cuota, la factura sobreviva.
 */
final class AddServicioCuotaIdToFacturasVenta extends AbstractMigration
{
    public function change(): void
    {
        $this->table('facturas_venta')
            ->addColumn('servicio_cuota_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'after' => 'estado',
                'comment' => 'Cuota del cronograma de servicio que esta factura cobra',
            ])
            ->addIndex(['servicio_cuota_id'], ['name' => 'idx_fv_cuota'])
            ->addForeignKey('servicio_cuota_id', 'servicio_cuotas', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_fv_cuota',
            ])
            ->update();
    }
}
