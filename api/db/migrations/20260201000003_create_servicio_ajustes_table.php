<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Tabla servicio_ajustes — historial de cambios de tarifa en servicios de mantenimiento.
 *
 * Cada ajuste registra el cambio del importe_base del servicio a partir de
 * una cuota específica. Las cuotas pendientes >= cuota_desde se recalculan.
 * Las cuotas facturadas / omitidas / canceladas NUNCA se tocan.
 *
 * Tipos:
 *  - programado: tipicamente generado por el ciclo de frecuencia_ajuste_meses del servicio
 *  - espontaneo: cargado ad-hoc por el admin
 */
final class CreateServicioAjustesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('servicio_ajustes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('servicio_id', 'biginteger', ['signed' => false])
            ->addColumn('tipo', 'enum', ['values' => ['programado', 'espontaneo']])
            ->addColumn('fecha_aplicacion', 'date', [
                'comment' => 'Fecha desde la cual rige el nuevo importe',
            ])
            ->addColumn('cuota_desde_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'comment' => 'Cuota específica desde la cual aplica (admin la elige)',
            ])
            ->addColumn('importe_anterior', 'decimal', [
                'precision' => 15, 'scale' => 2,
                'comment' => 'En la moneda del servicio',
            ])
            ->addColumn('importe_nuevo', 'decimal', [
                'precision' => 15, 'scale' => 2,
                'comment' => 'En la moneda del servicio',
            ])
            ->addColumn('porcentaje_variacion', 'decimal', [
                'precision' => 8, 'scale' => 4,
                'null' => true,
                'comment' => '% calculado: (nuevo - anterior) / anterior * 100',
            ])
            ->addColumn('aplicado', 'boolean', ['default' => false])
            ->addColumn('aplicado_at', 'datetime', ['null' => true])
            ->addColumn('aplicado_por', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('observaciones', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('created_by', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['servicio_id'], ['name' => 'idx_sa_servicio'])
            ->addIndex(['fecha_aplicacion'], ['name' => 'idx_sa_fecha'])
            ->addIndex(['aplicado'], ['name' => 'idx_sa_aplicado'])
            ->addForeignKey('servicio_id', 'servicios', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_sa_servicio',
            ])
            ->addForeignKey('cuota_desde_id', 'servicio_cuotas', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_sa_cuota',
            ])
            ->addForeignKey('aplicado_por', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_sa_user_apl',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_sa_user_crea',
            ])
            ->create();
    }
}
