<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Tabla notificaciones_enviadas — registra cada mail disparado por el cron
 * para garantizar idempotencia (no reenviar el mismo aviso si el cron corre dos veces).
 *
 * Tipos:
 *  - vencimiento_proximo: factura a vencer en X días (X de notif_dias_previos)
 *  - vencida: factura vencida hace X días (X de notif_dias_vencida)
 *  - ajuste_proximo: ajuste de servicio a aplicar en X días
 */
final class CreateNotificacionesEnviadasTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('notificaciones_enviadas', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('tipo', 'enum', [
                'values' => ['vencimiento_proximo', 'vencida', 'ajuste_proximo'],
            ])
            ->addColumn('entidad', 'string', ['limit' => 50])
            ->addColumn('entidad_id', 'biginteger', ['signed' => false])
            ->addColumn('dias_ref', 'integer', [
                'null' => true,
                'comment' => 'dias antes (negativo) o despues (positivo) de la fecha de referencia',
            ])
            ->addColumn('destinatarios', 'json', ['null' => true])
            ->addColumn('ok', 'boolean', ['default' => true])
            ->addColumn('error_msg', 'text', ['null' => true, 'limit' => MysqlAdapter::TEXT_LONG])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['entidad', 'entidad_id'], ['name' => 'idx_ne_entidad'])
            ->addIndex(['tipo'], ['name' => 'idx_ne_tipo'])
            ->addIndex(['created_at'], ['name' => 'idx_ne_created'])
            // Clave única para idempotencia: misma factura + tipo + dias_ref no se reenvía
            ->addIndex(['entidad', 'entidad_id', 'tipo', 'dias_ref'], [
                'unique' => true,
                'name' => 'uq_ne_idem',
            ])
            ->create();
    }
}
