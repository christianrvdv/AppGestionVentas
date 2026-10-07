<?php

declare(strict_types=1);

namespace App\Exception\Domain;

/**
 * Excepción base de dominio.
 * Extiende RuntimeException para no requerir catch obligatorio.
 * Todas las excepciones de reglas de negocio deben heredar de esta.
 */
class DomainException extends \RuntimeException
{
}