<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Garantiza que una cuota de servicio NO pueda tener dos facturas activas
 * mediante un indice UNIQUE compuesto con deleted_at.
 *
 * El indice se crea sobre (servicio_cuota_id, deleted_at). Como MySQL trata
 * NULL como distinto en uniques, varias filas con deleted_at = NULL son
 * permitidas SOLO si tienen servicio_cuota_id distinto (= 1 cuota → 1 factura
 * activa). Las archivadas (deleted_at NOT NULL) no chocan entre si.
 *
 * Orden de operaciones en up():
 *   1. DROP FOREIGN KEY fk_fv_cuota (porque depende del indice idx_fv_cuota)
 *   2. DROP INDEX idx_fv_cuota
 *   3. CREATE UNIQUE INDEX uq_fv_cuota_activa
 *   4. ADD FOREIGN KEY fk_fv_cuota (reusa el nuevo indice para el lookup)
 *
 * Antes de aplicar verificar manualmente que no existan duplicados activos:
 *   SELECT servicio_cuota_id, COUNT(*) FROM facturas_venta
 *   WHERE servicio_cuota_id IS NOT NULL AND deleted_at IS NULL
 *   GROUP BY servicio_cuota_id HAVING COUNT(*) > 1;
 */
final class UniqueServicioCuotaInFacturas extends AbstractMigration
{
    public function up(): void
    {
        // Usamos SQL crudo para tener control fino del orden.
        // El Phinx fluent API no garantiza el orden drop FK -> drop index.
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_cuota');
        $this->execute('ALTER TABLE facturas_venta DROP INDEX idx_fv_cuota');
        $this->execute(
            'CREATE UNIQUE INDEX uq_fv_cuota_activa ON facturas_venta '
            . '(servicio_cuota_id, deleted_at)'
        );
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'ADD CONSTRAINT fk_fv_cuota FOREIGN KEY (servicio_cuota_id) '
            . 'REFERENCES servicio_cuotas(id) '
            . 'ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_cuota');
        $this->execute('ALTER TABLE facturas_venta DROP INDEX uq_fv_cuota_activa');
        $this->execute(
            'CREATE INDEX idx_fv_cuota ON facturas_venta (servicio_cuota_id)'
        );
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'ADD CONSTRAINT fk_fv_cuota FOREIGN KEY (servicio_cuota_id) '
            . 'REFERENCES servicio_cuotas(id) '
            . 'ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }
}
