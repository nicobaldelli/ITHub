<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Garantiza que una cuota de servicio NO pueda tener dos facturas activas.
 *
 * - Cambia el indice existente idx_fv_cuota por uno UNIQUE
 * - El indice ignora soft-deleted (NULL en deleted_at): si se archiva una
 *   factura, la cuota vuelve a quedar disponible para una nueva. MySQL
 *   permite multiples NULL en uniques.
 *
 * Antes de aplicar esta migracion verificar manualmente que no existan
 * dos facturas activas para la misma servicio_cuota_id:
 *   SELECT servicio_cuota_id, COUNT(*) FROM facturas_venta
 *   WHERE servicio_cuota_id IS NOT NULL AND deleted_at IS NULL
 *   GROUP BY servicio_cuota_id HAVING COUNT(*) > 1;
 */
final class UniqueServicioCuotaInFacturas extends AbstractMigration
{
    public function up(): void
    {
        $this->table('facturas_venta')
            ->removeIndexByName('idx_fv_cuota')
            ->update();

        // Indice unique compuesto con deleted_at: facturas archivadas no bloquean
        // (MySQL trata NULLs como distintos en uniques, por lo que pueden
        // coexistir varias archivadas con la misma cuota)
        $this->execute(
            'CREATE UNIQUE INDEX uq_fv_cuota_activa ON facturas_venta '
            . '(servicio_cuota_id, deleted_at)'
        );
    }

    public function down(): void
    {
        $this->execute('DROP INDEX uq_fv_cuota_activa ON facturas_venta');
        $this->table('facturas_venta')
            ->addIndex(['servicio_cuota_id'], ['name' => 'idx_fv_cuota'])
            ->update();
    }
}
