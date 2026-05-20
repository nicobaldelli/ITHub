<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateUsersTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('users', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('nombre', 'string', ['limit' => 100])
            ->addColumn('apellido', 'string', ['limit' => 100])
            ->addColumn('email', 'string', ['limit' => 150])
            ->addColumn('password_hash', 'string', ['limit' => 255])
            ->addColumn('rol', 'enum', [
                'values' => ['admin', 'cobranzas', 'ventas', 'visualizador'],
            ])
            ->addColumn('activo', 'boolean', ['default' => true])
            ->addColumn('must_change_password', 'boolean', ['default' => false])
            ->addColumn('failed_login_attempts', 'integer', ['default' => 0])
            ->addColumn('locked_until', 'datetime', ['null' => true])
            ->addColumn('last_login', 'datetime', ['null' => true])
            ->addColumn('last_login_ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_users_email'])
            ->addIndex(['rol'], ['name' => 'idx_users_rol'])
            ->addIndex(['activo'], ['name' => 'idx_users_activo'])
            ->create();
    }
}
