<?php

use App\Kernel;
use App\Tests\Support\TestSchema;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Ensure bcmath is available
if (!extension_loaded('bcmath')) {
    throw new \LogicException('La extensión bcmath es requerida para los tests.');
}

/*
 * El esquema de test (app_control_ventas_test) se dropea y recrea UNA
 * vez por corrida de PHPUnit, ANTES de que se registre la extensión de
 * DAMA y de que StaticDriver abra la transacción de la corrida.
 *
 * Por qué aquí y no en setUp(): DAMA envuelve cada test en BEGIN y lo
 * revierte al terminar, de modo que si las tablas se crearan dentro de
 * un test el rollback las borraría de nuevo y el siguiente test
 * arrancaría sin esquema. Creado fuera de la transacción, el esquema
 * permanece y el rollback de cada test solo deshace los DATOS — que es
 * exactamente el aislamiento que buscábamos, sin pagar DROP/CREATE en
 * cada test.
 */
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

try {
    // framework.test=true en el entorno de test: da acceso a servicios
    // privados como EntityManagerInterface.
    $container = $kernel->getContainer()->get('test.service_container');

    TestSchema::recreate($container->get(EntityManagerInterface::class));
} finally {
    $kernel->shutdown();
}
