<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Esquema de la base para los tests de integración, en SQLite.
 *
 * Las migraciones de Phinx usan `enum` y constantes de MysqlAdapter, así que no
 * corren en SQLite. Este esquema las replica con el Schema builder de Illuminate:
 * mismas tablas, columnas, nullables, defaults, únicos y FKs. Los `enum` pasan
 * a `string` (la validación de valores la hacen los validadores, no la base).
 *
 * Si se agrega una migración, hay que reflejarla acá (TestSchemaTest lo
 * recuerda comparando la lista de tablas).
 */
final class TestSchema
{
    /** @var string[] Tablas en orden de creación (padres primero). */
    public const TABLAS = [
        'users',
        'clientes',
        'facturas_venta',
        'factura_archivos',
        'auditoria',
        'config_app',
        'refresh_tokens',
        'servicios',
        'servicio_cuotas',
        'servicio_ajustes',
        'notificaciones_enviadas',
    ];

    public static function create(Builder $schema): void
    {
        $schema->create('users', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('nombre', 100);
            $t->string('apellido', 100);
            $t->string('email', 150)->unique('uq_users_email');
            $t->string('password_hash', 255);
            $t->string('rol', 20);
            $t->boolean('activo')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->integer('failed_login_attempts')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('last_login')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
        });

        $schema->create('clientes', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('razon_social', 200);
            $t->string('cuit', 13)->unique('uq_clientes_cuit');
            $t->string('cuit_pais', 20)->nullable();
            $t->string('tipo_default', 20)->nullable();
            $t->string('direccion', 255)->nullable();
            $t->string('banco', 100)->nullable();
            $t->string('cbu', 22)->nullable();
            $t->string('alias', 30)->nullable();
            $t->integer('plazo_pago_default')->nullable();
            $t->string('mail_envio_factura', 150)->nullable();
            $t->string('contacto_envio_factura', 150)->nullable();
            $t->string('telefono_contacto_proveedores', 50)->nullable();
            $t->string('mail_gestion_cobranza', 150)->nullable();
            $t->string('contacto_gestion_cobranza', 150)->nullable();
            $t->string('telefono_contacto_cobranza', 50)->nullable();
            $t->text('observaciones')->nullable();
            $t->boolean('activo')->default(true);
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->dateTime('deleted_at')->nullable();
        });

        // servicios antes que facturas_venta por la FK servicio_cuota_id
        $schema->create('servicios', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cliente_id');
            $t->string('tipo', 20);
            $t->string('nombre', 200);
            $t->text('descripcion')->nullable();
            $t->text('template_factura')->nullable();
            $t->string('tipo_factura_default', 20)->default('A');
            $t->string('moneda', 3)->default('ARS');
            $t->decimal('importe_base', 15, 2);
            $t->decimal('iva_porcentaje', 5, 2)->default(21.00);
            $t->date('fecha_inicio');
            $t->date('fecha_fin')->nullable();
            $t->string('modo_facturacion', 20)->nullable();
            $t->unsignedTinyInteger('dia_facturacion')->nullable();
            $t->integer('intervalo_dias')->nullable();
            $t->integer('frecuencia_ajuste_meses')->nullable();
            $t->integer('aviso_dias_previos')->nullable();
            $t->string('estado', 20)->default('activo');
            $t->dateTime('pausado_at')->nullable();
            $t->text('observaciones')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->dateTime('deleted_at')->nullable();
            $t->foreign('cliente_id')->references('id')->on('clientes');
            $t->foreign('created_by')->references('id')->on('users');
            $t->foreign('updated_by')->references('id')->on('users');
        });

        $schema->create('servicio_cuotas', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('servicio_id');
            $t->unsignedInteger('numero_cuota');
            $t->unsignedInteger('total_cuotas')->nullable();
            $t->decimal('porcentaje', 5, 2)->nullable();
            $t->decimal('importe', 15, 2);
            $t->date('fecha_prevista');
            $t->unsignedBigInteger('factura_id')->nullable();
            $t->string('estado', 20)->default('pendiente');
            $t->string('etiqueta', 100)->nullable();
            $t->boolean('es_proporcional')->default(false);
            $t->unsignedInteger('dias_cubiertos')->nullable();
            $t->text('observaciones')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->unique(['servicio_id', 'numero_cuota'], 'uq_sc_servicio_numero');
            $t->foreign('servicio_id')->references('id')->on('servicios')->cascadeOnDelete();
        });

        $schema->create('facturas_venta', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('numero_factura', 50)->unique('uq_fv_numero');
            $t->unsignedBigInteger('cliente_id');
            $t->string('tipo', 20);
            $t->string('cuit', 13);
            $t->string('cuit_pais', 20)->nullable();
            $t->string('moneda', 3)->default('ARS');
            $t->decimal('importe_sin_iva', 15, 2)->default(0);
            $t->decimal('importe_con_iva', 15, 2)->default(0);
            $t->decimal('importe_total_pesos', 15, 2)->nullable()->default(0);
            $t->decimal('tdc', 10, 4)->nullable();
            $t->decimal('retenciones', 15, 2)->default(0);
            $t->decimal('total_cobrado', 15, 2)->default(0);
            $t->text('detalle_factura')->nullable();
            $t->unsignedTinyInteger('numero_mes')->nullable();
            $t->string('mes_cubierto', 50)->nullable();
            $t->date('fecha_factura');
            $t->date('fecha_envio')->nullable();
            $t->string('banco', 100)->nullable();
            $t->date('vencimiento')->nullable();
            $t->string('cbu', 22)->nullable();
            $t->string('alias', 30)->nullable();
            $t->integer('plazo_pago')->nullable();
            $t->date('fecha_pago')->nullable();
            $t->string('direccion', 255)->nullable();
            $t->string('mail_envio_factura', 150)->nullable();
            $t->string('contacto_envio_factura', 150)->nullable();
            $t->string('telefono_contacto_proveedores', 50)->nullable();
            $t->string('mail_gestion_cobranza', 150)->nullable();
            $t->string('contacto_gestion_cobranza', 150)->nullable();
            $t->string('telefono_contacto_cobranza', 50)->nullable();
            $t->text('observaciones')->nullable();
            $t->boolean('check_cobranza')->default(false);
            $t->unsignedBigInteger('check_cobranza_user_id')->nullable();
            $t->dateTime('check_cobranza_fecha')->nullable();
            $t->string('drive_folder_id', 100)->nullable();
            $t->string('estado', 20)->default('emitida');
            $t->unsignedBigInteger('servicio_cuota_id')->nullable();
            // nullable desde 20260301000005 (facturas generadas por cron)
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->dateTime('deleted_at')->nullable();
            // 20260301000003: una sola factura activa por cuota
            $t->unique(['servicio_cuota_id', 'deleted_at'], 'uq_fv_cuota_activa');
            $t->foreign('cliente_id')->references('id')->on('clientes');
            $t->foreign('servicio_cuota_id')->references('id')->on('servicio_cuotas')->nullOnDelete();
        });

        $schema->create('factura_archivos', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('factura_id');
            $t->string('drive_file_id', 100);
            $t->string('nombre_archivo', 255);
            $t->string('mime_type', 100)->nullable();
            $t->unsignedBigInteger('tamanio_bytes')->nullable();
            $t->string('drive_view_url', 500)->nullable();
            $t->string('drive_download_url', 500)->nullable();
            $t->unsignedBigInteger('uploaded_by');
            $t->dateTime('created_at');
            $t->foreign('factura_id')->references('id')->on('facturas_venta')->cascadeOnDelete();
        });

        $schema->create('auditoria', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('entidad', 50);
            $t->unsignedBigInteger('entidad_id')->nullable();
            $t->string('accion', 30);
            $t->json('campos_modificados')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('request_id', 64)->nullable();
            $t->dateTime('created_at');
        });

        $schema->create('config_app', function (Blueprint $t): void {
            $t->string('clave', 100)->primary();
            $t->text('valor')->nullable();
            $t->string('tipo', 10)->default('string');
            $t->string('descripcion', 255)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->dateTime('updated_at');
        });

        $schema->create('refresh_tokens', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('user_id');
            $t->char('token_hash', 64)->unique('uq_rt_token');
            $t->char('family_id', 36);
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->unsignedBigInteger('replaced_by_id')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('ip', 45)->nullable();
            $t->dateTime('created_at');
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $schema->create('servicio_ajustes', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('servicio_id');
            $t->string('tipo', 20);
            $t->date('fecha_aplicacion');
            $t->unsignedBigInteger('cuota_desde_id')->nullable();
            $t->decimal('importe_anterior', 15, 2);
            $t->decimal('importe_nuevo', 15, 2);
            $t->decimal('porcentaje_variacion', 8, 4)->nullable();
            $t->boolean('aplicado')->default(false);
            $t->dateTime('aplicado_at')->nullable();
            $t->unsignedBigInteger('aplicado_por')->nullable();
            $t->text('observaciones')->nullable();
            $t->unsignedBigInteger('created_by');
            $t->dateTime('created_at');
            $t->dateTime('updated_at');
            $t->foreign('servicio_id')->references('id')->on('servicios')->cascadeOnDelete();
        });

        $schema->create('notificaciones_enviadas', function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('tipo', 30);
            $t->string('entidad', 50);
            $t->unsignedBigInteger('entidad_id');
            $t->integer('dias_ref')->nullable();
            $t->json('destinatarios')->nullable();
            $t->boolean('ok')->default(true);
            $t->text('error_msg')->nullable();
            $t->dateTime('created_at');
            $t->unique(['entidad', 'entidad_id', 'tipo', 'dias_ref'], 'uq_ne_idem');
        });
    }
}
