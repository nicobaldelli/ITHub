<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateConfigAppTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('config_app', ['id' => false, 'primary_key' => ['clave']])
            ->addColumn('clave', 'string', ['limit' => 100])
            ->addColumn('valor', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('tipo', 'enum', ['values' => ['string', 'int', 'bool', 'json'], 'default' => 'string'])
            ->addColumn('descripcion', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('updated_by', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('updated_by', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_cfg_user',
            ])
            ->create();
    }
}
