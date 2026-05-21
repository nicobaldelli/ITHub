<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Tabla servicios — acuerdo comercial con un cliente.
 *
 * Dos tipos:
 *  - proyecto: alcance cerrado, N cuotas con porcentajes que suman 100%.
 *  - mantenimiento: facturación periódica. Puede ser indefinido (sin fecha_fin).
 */
final class CreateServiciosTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('servicios', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('cliente_id', 'biginteger', ['signed' => false])
            ->addColumn('tipo', 'enum', ['values' => ['proyecto', 'mantenimiento']])
            ->addColumn('nombre', 'string', ['limit' => 200])
            ->addColumn('descripcion', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('moneda', 'enum', ['values' => ['ARS', 'USD'], 'default' => 'ARS'])
            // importe_base: para PROYECTO = importe total del proyecto (suma de cuotas).
            //               para MANTENIMIENTO = importe por cuota vigente (cambia con ajustes).
            ->addColumn('importe_base', 'decimal', ['precision' => 15, 'scale' => 2])
            ->addColumn('fecha_inicio', 'date')
            // fecha_fin NULL = mantenimiento indefinido. Inválido para proyecto.
            ->addColumn('fecha_fin', 'date', ['null' => true])
            // Solo aplica a mantenimiento:
            ->addColumn('modo_facturacion', 'enum', [
                'values' => ['mes_calendario', 'intervalo_dias'],
                'null' => true,
            ])
            ->addColumn('dia_facturacion', 'integer', [
                'limit' => MysqlAdapter::INT_TINY,
                'signed' => false,
                'null' => true,
                'comment' => '1-31, día del mes en modo mes_calendario',
            ])
            ->addColumn('intervalo_dias', 'integer', [
                'null' => true,
                'comment' => 'cantidad de días entre cuotas en modo intervalo_dias',
            ])
            ->addColumn('frecuencia_ajuste_meses', 'integer', [
                'null' => true,
                'comment' => 'cada cuántos meses se revisa el precio; NULL = sin ajustes programados',
            ])
            ->addColumn('aviso_dias_previos', 'integer', [
                'null' => true,
                'comment' => 'override del default global de avisos; NULL = usa config_app',
            ])
            ->addColumn('estado', 'enum', [
                'values' => ['activo', 'pausado', 'completado', 'cancelado'],
                'default' => 'activo',
            ])
            ->addColumn('pausado_at', 'datetime', ['null' => true])
            ->addColumn('observaciones', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('created_by', 'biginteger', ['signed' => false])
            ->addColumn('updated_by', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['cliente_id'], ['name' => 'idx_srv_cliente'])
            ->addIndex(['tipo'], ['name' => 'idx_srv_tipo'])
            ->addIndex(['estado'], ['name' => 'idx_srv_estado'])
            ->addIndex(['fecha_inicio'], ['name' => 'idx_srv_inicio'])
            ->addIndex(['fecha_fin'], ['name' => 'idx_srv_fin'])
            ->addIndex(['deleted_at'], ['name' => 'idx_srv_deleted'])
            ->addForeignKey('cliente_id', 'clientes', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_srv_cliente',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_srv_created',
            ])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_srv_updated',
            ])
            ->create();
    }
}
