<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRefreshTokensTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('refresh_tokens', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('token_hash', 'char', ['limit' => 64, 'comment' => 'SHA-256 hex del refresh'])
            ->addColumn('family_id', 'char', ['limit' => 36, 'comment' => 'UUID v4 que agrupa familia de tokens rotados'])
            ->addColumn('expires_at', 'datetime')
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('replaced_by_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['token_hash'], ['unique' => true, 'name' => 'uq_rt_token'])
            ->addIndex(['user_id'], ['name' => 'idx_rt_user'])
            ->addIndex(['family_id'], ['name' => 'idx_rt_family'])
            ->addIndex(['expires_at'], ['name' => 'idx_rt_expires'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_rt_user',
            ])
            ->create();
    }
}
