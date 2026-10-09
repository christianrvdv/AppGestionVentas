<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/**
 * Recreación explícita del esquema de test (DROP ... CASCADE + CREATE)
 * a partir de los metadatos de las entidades.
 *
 * Uso principal: tests/bootstrap.php, UNA vez por corrida y FUERA de la
 * transacción de DAMA — ver el comentario ahí. Desde Fase 1.5b los tests
 * NO recrean el esquema en setUp(): DAMA los aísla con BEGIN/ROLLBACK
 * por test, así que dropear tablas en cada uno era puro costo.
 *
 * Se mantiene como escape hatch para un test que necesite DDL explícito:
 * en PostgreSQL el DDL es transaccional, así que un recreateSchema()
 * dentro de un test también se revierte solo al terminar el test.
 */
final class TestSchema
{
    public static function recreate(EntityManagerInterface $em): void
    {
        $connection = $em->getConnection();
        $schemaManager = $connection->createSchemaManager();

        // DROP ... CASCADE porque las tablas tienen FKs entre sí y el
        // orden alfabético de listTableNames() no respeta las
        // dependencias.
        foreach ($schemaManager->listTableNames() as $tableName) {
            $connection->executeStatement(
                'DROP TABLE IF EXISTS '.$connection->quoteSingleIdentifier($tableName).' CASCADE'
            );
        }

        $tool = new SchemaTool($em);
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }
}
