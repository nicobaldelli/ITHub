<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Tabla servicio_cuotas — cronograma de facturación.
 *
 * Una cuota = una factura por defecto. Se vincula a una factura cuando se emite
 * (factura_id NOT NULL) y pasa a estado=facturada.
 *
 * Para mantenimientos indefinidos no hay total_cuotas; las cuotas se generan
 * en rolling window por cron mensual.
 */
final class CreateServicioCuotasTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('servicio_cuotas', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('servicio_id', 'biginteger', ['signed' => false])
            ->addColumn('numero_cuota', 'integer', ['signed' => false])
            ->addColumn('total_cuotas', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'NULL para mantenimientos indefinidos',
            ])
            ->addColumn('porcentaje', 'decimal', [
                'precision' => 5, 'scale' => 2, 'null' => true,
                'comment' => 'Solo para proyectos: % del importe_base del servicio',
            ])
            ->addColumn('importe', 'decimal', [
                'precision' => 15, 'scale' => 2,
                'comment' => 'Importe en la moneda del servicio',
            ])
            ->addColumn('fecha_prevista', 'date')
            ->addColumn('factura_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('estado', 'enum', [
                'values' => ['pendiente', 'facturada', 'omitida', 'cancelada'],
                'default' => 'pendiente',
            ])
            ->addColumn('etiqueta', 'string', [
                'limit' => 100,
                'null' => true,
                'comment' => 'Ej: "Anticipo", "Hito 1", "Junio 2026", "1 de 12"',
            ])
            ->addColumn('es_proporcional', 'boolean', [
                'default' => false,
                'comment' => 'true si esta cuota cubre menos días que el intervalo (última cuota de un servicio)',
            ])
            ->addColumn('dias_cubiertos', 'integer', [
                'signed' => false,
                'null' => true,
                'comment' => 'Para cuotas proporcionales: cuántos días cubre (intervalo_dias)',
            ])
            ->addColumn('observaciones', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['servicio_id'], ['name' => 'idx_sc_servicio'])
            ->addIndex(['fecha_prevista'], ['name' => 'idx_sc_fecha'])
            ->addIndex(['estado'], ['name' => 'idx_sc_estado'])
            ->addIndex(['factura_id'], ['name' => 'idx_sc_factura'])
            ->addIndex(['servicio_id', 'numero_cuota'], [
                'unique' => true,
                'name' => 'uq_sc_servicio_numero',
            ])
            ->addForeignKey('servicio_id', 'servicios', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_sc_servicio',
            ])
            ->addForeignKey('factura_id', 'facturas_venta', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_sc_factura',
            ])
            ->create();
    }
}
