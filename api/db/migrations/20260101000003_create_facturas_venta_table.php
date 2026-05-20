<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateFacturasVentaTable extends AbstractMigration
{
    public function change(): void
    {
        $tiposFactura = [
            'A', 'B', 'E',
            'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B',
            'NC_A', 'NC_B', 'NC_E',
            'ND_A', 'ND_B', 'ND_E',
        ];

        $this->table('facturas_venta', ['signed' => false, 'id' => 'id'])
            ->addColumn('numero_factura', 'string', ['limit' => 50])
            ->addColumn('cliente_id', 'biginteger', ['signed' => false])
            ->addColumn('tipo', 'enum', ['values' => $tiposFactura])
            ->addColumn('cuit', 'string', ['limit' => 13, 'comment' => 'Snapshot del CUIT al momento de emitir'])
            ->addColumn('cuit_pais', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('moneda', 'enum', ['values' => ['ARS', 'USD'], 'default' => 'ARS'])
            ->addColumn('importe_sin_iva', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
            ->addColumn('importe_con_iva', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
            ->addColumn('importe_total_pesos', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
            ->addColumn('tdc', 'decimal', ['precision' => 10, 'scale' => 4, 'null' => true])
            ->addColumn('retenciones', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
            ->addColumn('total_cobrado', 'decimal', ['precision' => 15, 'scale' => 2, 'default' => 0])
            ->addColumn('detalle_factura', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('numero_mes', 'integer', ['null' => true, 'limit' => MysqlAdapter::INT_TINY, 'signed' => false])
            ->addColumn('mes_cubierto', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('fecha_factura', 'date')
            ->addColumn('fecha_envio', 'date', ['null' => true])
            ->addColumn('banco', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('vencimiento', 'date', ['null' => true])
            ->addColumn('cbu', 'string', ['limit' => 22, 'null' => true])
            ->addColumn('alias', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('plazo_pago', 'integer', ['null' => true])
            ->addColumn('fecha_pago', 'date', ['null' => true])
            ->addColumn('direccion', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('mail_envio_factura', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('contacto_envio_factura', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefono_contacto_proveedores', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('mail_gestion_cobranza', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('contacto_gestion_cobranza', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefono_contacto_cobranza', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('observaciones', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('check_cobranza', 'boolean', ['default' => false])
            ->addColumn('check_cobranza_user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('check_cobranza_fecha', 'datetime', ['null' => true])
            ->addColumn('drive_folder_id', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('estado', 'enum', [
                'values' => ['borrador', 'emitida', 'cobrada', 'vencida', 'anulada'],
                'default' => 'emitida',
            ])
            ->addColumn('created_by', 'biginteger', ['signed' => false])
            ->addColumn('updated_by', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['numero_factura'], ['unique' => true, 'name' => 'uq_fv_numero'])
            ->addIndex(['cliente_id'], ['name' => 'idx_fv_cliente'])
            ->addIndex(['fecha_factura'], ['name' => 'idx_fv_fecha'])
            ->addIndex(['vencimiento'], ['name' => 'idx_fv_vencimiento'])
            ->addIndex(['fecha_pago'], ['name' => 'idx_fv_fecha_pago'])
            ->addIndex(['tipo'], ['name' => 'idx_fv_tipo'])
            ->addIndex(['moneda'], ['name' => 'idx_fv_moneda'])
            ->addIndex(['check_cobranza'], ['name' => 'idx_fv_check'])
            ->addIndex(['estado'], ['name' => 'idx_fv_estado'])
            ->addIndex(['deleted_at'], ['name' => 'idx_fv_deleted'])
            ->addForeignKey('cliente_id', 'clientes', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_fv_cliente',
            ])
            ->addForeignKey('check_cobranza_user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_fv_check_usr',
            ])
            ->addForeignKey('created_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_fv_created',
            ])
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_fv_updated',
            ])
            ->create();
    }
}
