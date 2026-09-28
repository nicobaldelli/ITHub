<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

final class ConfigAppSeeder extends AbstractSeed
{
    public function run(): void
    {
        $now = date('Y-m-d H:i:s');
        $defaults = [
            ['clave' => 'drive_root_folder_id', 'valor' => '', 'tipo' => 'string',
                'descripcion' => 'ID de la carpeta raíz de Google Drive donde se crea la jerarquía año/mes/cliente'],
            ['clave' => 'smtp_host', 'valor' => '', 'tipo' => 'string', 'descripcion' => 'Servidor SMTP'],
            ['clave' => 'smtp_port', 'valor' => '587', 'tipo' => 'int', 'descripcion' => 'Puerto SMTP'],
            ['clave' => 'smtp_user', 'valor' => '', 'tipo' => 'string', 'descripcion' => 'Usuario SMTP'],
            ['clave' => 'smtp_pass', 'valor' => '', 'tipo' => 'string', 'descripcion' => 'Password SMTP (cifrado at-rest recomendado)'],
            ['clave' => 'smtp_from', 'valor' => 'facturacion@intellihelp.tech', 'tipo' => 'string',
                'descripcion' => 'Email remitente'],
            ['clave' => 'smtp_from_name', 'valor' => 'ITHub Facturación', 'tipo' => 'string',
                'descripcion' => 'Nombre del remitente'],
            ['clave' => 'smtp_encryption', 'valor' => 'tls', 'tipo' => 'string',
                'descripcion' => 'Cifrado SMTP: tls (puerto 587) o ssl (puerto 465)'],
            ['clave' => 'notif_dias_previos', 'valor' => '[3,1,0]', 'tipo' => 'json',
                'descripcion' => 'Días antes del vencimiento para mandar recordatorio'],
            ['clave' => 'notif_dias_vencida', 'valor' => '[1,7,15,30]', 'tipo' => 'json',
                'descripcion' => 'Días después del vencimiento para mandar aviso de vencida'],
            ['clave' => 'notif_cc_emails', 'valor' => '[]', 'tipo' => 'json',
                'descripcion' => 'Emails en copia para todas las notificaciones'],
            ['clave' => 'cron_hora_notif', 'valor' => '09:00', 'tipo' => 'string',
                'descripcion' => 'Hora a la que corre el cron de vencimientos'],
        ];

        // Idempotente: `clave` es PK, así que solo se insertan las que faltan.
        // Permite volver a correr el seed tras agregar claves nuevas sin pisar valores.
        $insertadas = 0;
        foreach ($defaults as $row) {
            $existe = $this->fetchRow(sprintf(
                "SELECT clave FROM config_app WHERE clave = '%s' LIMIT 1",
                addslashes($row['clave'])
            ));
            if ($existe) {
                continue;
            }
            $row['updated_at'] = $now;
            $this->table('config_app')->insert($row)->save();
            $insertadas++;
        }

        echo "[seed] Config inicial: {$insertadas} clave(s) nueva(s), " . (count($defaults) - $insertadas) . " ya existían.\n";
    }
}
