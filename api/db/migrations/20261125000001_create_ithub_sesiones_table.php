<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Sesiones abiertas con el ingreso de ITHub (sesión única de Intellihelp).
 *
 * Una fila por familia de refresh tokens nacida de un ingreso por ITHub:
 * guarda el `sid` de la sesión de ITHub (para cerrarla cuando ITHub avisa
 * por detrás, back-channel logout) y el id_token (para salir de ITHub sin
 * que pida confirmación). `cerrada_en` marca que ITHub avisó el cierre.
 */
final class CreateIthubSesionesTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('ithub_sesiones', ['id' => false, 'primary_key' => ['family_id']])
            ->addColumn('family_id', 'char', ['limit' => 36, 'comment' => 'family_id de refresh_tokens'])
            ->addColumn('user_id', 'biginteger', ['signed' => false])
            ->addColumn('sid', 'string', ['limit' => 128, 'comment' => 'sid de la sesión de ITHub'])
            ->addColumn('sub', 'string', ['limit' => 64, 'comment' => 'id de la persona en ITHub'])
            ->addColumn('id_token', 'text')
            ->addColumn('created_at', 'datetime')
            ->addColumn('cerrada_en', 'datetime', ['null' => true])
            ->addIndex(['sid'], ['name' => 'idx_ithub_sid'])
            ->addIndex(['sub'], ['name' => 'idx_ithub_sub'])
            ->addIndex(['user_id'], ['name' => 'idx_ithub_user'])
            ->addForeignKey('user_id', 'users', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_ithub_user',
            ])
            ->create();
    }
}
