<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateClientesTable extends AbstractMigration
{
    public function change(): void
    {
        $tiposFactura = [
            'A', 'B', 'E',
            'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B',
            'NC_A', 'NC_B', 'NC_E',
            'ND_A', 'ND_B', 'ND_E',
        ];

        $this->table('clientes', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('razon_social', 'string', ['limit' => 200])
            ->addColumn('cuit', 'string', ['limit' => 13])
            ->addColumn('cuit_pais', 'string', ['limit' => 20, 'null' => true])
            ->addColumn('tipo_default', 'enum', ['values' => $tiposFactura, 'null' => true])
            ->addColumn('direccion', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('banco', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('cbu', 'string', ['limit' => 22, 'null' => true])
            ->addColumn('alias', 'string', ['limit' => 30, 'null' => true])
            ->addColumn('plazo_pago_default', 'integer', ['null' => true])
            ->addColumn('mail_envio_factura', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('contacto_envio_factura', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefono_contacto_proveedores', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('mail_gestion_cobranza', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('contacto_gestion_cobranza', 'string', ['limit' => 150, 'null' => true])
            ->addColumn('telefono_contacto_cobranza', 'string', ['limit' => 50, 'null' => true])
            ->addColumn('observaciones', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('activo', 'boolean', ['default' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addColumn('deleted_at', 'datetime', ['null' => true])
            ->addIndex(['cuit'], ['unique' => true, 'name' => 'uq_clientes_cuit'])
            ->addIndex(['razon_social'], ['name' => 'idx_clientes_razon_social'])
            ->addIndex(['activo'], ['name' => 'idx_clientes_activo'])
            ->addIndex(['deleted_at'], ['name' => 'idx_clientes_deleted'])
            ->create();
    }
}
