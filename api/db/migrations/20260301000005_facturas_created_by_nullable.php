<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Hace `created_by` y `updated_by` de facturas_venta nullable.
 *
 * Necesario porque las facturas generadas por el cron automatico no
 * tienen un user asociado — son del sistema. Tener un usuario "sistema"
 * fake en users complica auditorias y reportes; mejor permitir NULL y
 * dejar claro en UI que "sin user" significa "generada por el cron".
 */
final class FacturasCreatedByNullable extends AbstractMigration
{
    public function up(): void
    {
        // Drop FKs primero (apuntan a la columna)
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_created');
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_updated');

        // Modificar columnas a nullable
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'MODIFY created_by BIGINT UNSIGNED NULL, '
            . 'MODIFY updated_by BIGINT UNSIGNED NULL'
        );

        // Recrear FKs con SET NULL (si se borra un user, las facturas siguen, sin user)
        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'ADD CONSTRAINT fk_fv_created FOREIGN KEY (created_by) '
            . 'REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE, '
            . 'ADD CONSTRAINT fk_fv_updated FOREIGN KEY (updated_by) '
            . 'REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_created');
        $this->execute('ALTER TABLE facturas_venta DROP FOREIGN KEY fk_fv_updated');

        // Para volver atrás necesitamos asegurarnos de que no haya NULLs.
        // Si hay, los seteamos a 1 (asumiendo que existe el admin con id=1).
        $this->execute('UPDATE facturas_venta SET created_by = 1 WHERE created_by IS NULL');
        $this->execute('UPDATE facturas_venta SET updated_by = 1 WHERE updated_by IS NULL');

        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'MODIFY created_by BIGINT UNSIGNED NOT NULL, '
            . 'MODIFY updated_by BIGINT UNSIGNED NOT NULL'
        );

        $this->execute(
            'ALTER TABLE facturas_venta '
            . 'ADD CONSTRAINT fk_fv_created FOREIGN KEY (created_by) '
            . 'REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE, '
            . 'ADD CONSTRAINT fk_fv_updated FOREIGN KEY (updated_by) '
            . 'REFERENCES users(id) ON DELETE RESTRICT ON UPDATE CASCADE'
        );
    }
}
