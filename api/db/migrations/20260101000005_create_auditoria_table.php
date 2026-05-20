<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

final class CreateAuditoriaTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('auditoria', ['signed' => false, 'id' => 'id'])
            ->addColumn('user_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('entidad', 'string', ['limit' => 50])
            ->addColumn('entidad_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('accion', 'enum', [
                'values' => [
                    'crear', 'editar', 'eliminar', 'marcar_cobrada',
                    'login', 'login_fallido', 'logout',
                    'export', 'import',
                    'archivo_subido', 'archivo_eliminado',
                    'config_actualizada', 'cambio_password', 'reset_password',
                ],
            ])
            ->addColumn('campos_modificados', 'json', ['null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('request_id', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['user_id'], ['name' => 'idx_aud_user'])
            ->addIndex(['entidad', 'entidad_id'], ['name' => 'idx_aud_entidad'])
            ->addIndex(['accion'], ['name' => 'idx_aud_accion'])
            ->addIndex(['created_at'], ['name' => 'idx_aud_created'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'SET_NULL', 'update' => 'CASCADE', 'constraint' => 'fk_aud_user',
            ])
            ->create();
    }
}
