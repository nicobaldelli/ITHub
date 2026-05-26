<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Agrega:
 *  - iva_porcentaje: alícuota de IVA del servicio (0, 10.5 o 21).
 *    Se aplica al importe_base para calcular el total con IVA.
 *  - template_factura: plantilla del campo detalle_factura para cada cuota.
 *    Soporta placeholders: {MES_NOMBRE}, {ANIO}, {NUMERO_MES},
 *    {NUMERO_MES_DESDE_TARIFA}, {INPUT:nombre:default}.
 */
final class AddIvaAndTemplateToServicios extends AbstractMigration
{
    public function change(): void
    {
        $this->table('servicios')
            ->addColumn('iva_porcentaje', 'decimal', [
                'precision' => 5,
                'scale' => 2,
                'default' => 21.00,
                'after' => 'importe_base',
                'comment' => 'Alicuota IVA: 0, 10.5 o 21',
            ])
            ->addColumn('template_factura', 'text', [
                'null' => true,
                'limit' => MysqlAdapter::TEXT_LONG,
                'after' => 'descripcion',
                'comment' => 'Plantilla del detalle de factura con placeholders',
            ])
            ->update();
    }
}
