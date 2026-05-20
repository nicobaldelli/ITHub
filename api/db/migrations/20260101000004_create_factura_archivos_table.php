<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateFacturaArchivosTable extends AbstractMigration
{
    public function change(): void
    {
        $this->table('factura_archivos', ['signed' => false, 'id' => 'id'])
            ->addColumn('factura_id', 'biginteger', ['signed' => false])
            ->addColumn('drive_file_id', 'string', ['limit' => 100])
            ->addColumn('nombre_archivo', 'string', ['limit' => 255])
            ->addColumn('mime_type', 'string', ['limit' => 100, 'null' => true])
            ->addColumn('tamanio_bytes', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('drive_view_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('drive_download_url', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('uploaded_by', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['factura_id'], ['name' => 'idx_fa_factura'])
            ->addIndex(['drive_file_id'], ['name' => 'idx_fa_drive'])
            ->addForeignKey('factura_id', 'facturas_venta', 'id', [
                'delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_fa_factura',
            ])
            ->addForeignKey('uploaded_by', 'users', 'id', [
                'delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_fa_user',
            ])
            ->create();
    }
}
