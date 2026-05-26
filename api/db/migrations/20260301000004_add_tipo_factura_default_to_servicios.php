<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Agrega `tipo_factura_default` a servicios — el tipo que se usa cuando el
 * cron diario genera la factura automaticamente al llegar la fecha de la
 * cuota. El admin lo elige al crear el servicio.
 *
 * Default 'A' (el mas comun en facturacion B2B en Argentina).
 */
final class AddTipoFacturaDefaultToServicios extends AbstractMigration
{
    public function change(): void
    {
        $tiposFactura = [
            'A', 'B', 'E',
            'CREDITO_MIPYME_A', 'CREDITO_MIPYME_B',
            'NC_A', 'NC_B', 'NC_E',
            'ND_A', 'ND_B', 'ND_E',
        ];

        $this->table('servicios')
            ->addColumn('tipo_factura_default', 'enum', [
                'values' => $tiposFactura,
                'default' => 'A',
                'after' => 'template_factura',
                'comment' => 'Tipo de factura que asigna el cron al generar facturas automaticas',
            ])
            ->update();
    }
}
