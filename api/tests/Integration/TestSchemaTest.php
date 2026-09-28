<?php

declare(strict_types=1);

namespace ITHub\Api\Tests\Integration;

use ITHub\Api\Tests\Support\DatabaseTestCase;
use ITHub\Api\Tests\Support\TestSchema;

/**
 * Sanidad de la infraestructura: que el esquema SQLite de los tests cubra
 * todas las tablas que crean las migraciones de Phinx. Si se agrega una
 * migración con una tabla nueva, este test avisa que hay que reflejarla en
 * TestSchema.
 */
final class TestSchemaTest extends DatabaseTestCase
{
    public function testTodasLasTablasDelEsquemaExisten(): void
    {
        $schema = $this->capsule->getConnection()->getSchemaBuilder();
        foreach (TestSchema::TABLAS as $tabla) {
            self::assertTrue($schema->hasTable($tabla), "Falta la tabla {$tabla}");
        }
    }

    public function testElEsquemaCubreLasTablasQueCreanLasMigraciones(): void
    {
        $migraciones = glob(dirname(__DIR__, 2) . '/db/migrations/*.php') ?: [];
        $creadas = [];
        foreach ($migraciones as $archivo) {
            $src = (string) file_get_contents($archivo);
            if (preg_match_all("/->table\('([a-z_]+)'.*?->create\(\)/s", $src, $m)) {
                foreach ($m[1] as $t) {
                    $creadas[$t] = true;
                }
            }
        }

        self::assertNotEmpty($creadas, 'no se detectó ninguna tabla en las migraciones');
        foreach (array_keys($creadas) as $tabla) {
            self::assertContains($tabla, TestSchema::TABLAS, "La migración crea `{$tabla}` pero TestSchema no la tiene");
        }
    }

    public function testLasClavesForaneasEstanActivas(): void
    {
        $fk = $this->capsule->getConnection()->selectOne('PRAGMA foreign_keys');
        self::assertSame(1, (int) ((array) $fk)['foreign_keys']);
    }
}
