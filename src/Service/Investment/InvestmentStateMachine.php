<?php

declare(strict_types=1);

namespace App\Service\Investment;

use App\Entity\Investment;
use App\Exception\Domain\InvalidStateTransitionException;

/**
 * Máquina de estados explícita para Investment.
 *
 * TABLA DE TRANSICIONES PERMITIDAS:
 *
 * | Estado Origen | Estado Destino | Método              | Condiciones                              |
 * |---------------|----------------|---------------------|------------------------------------------|
 * | OPEN          | PARTIAL        | markPartial()       | Hay ventas pero no recuperado todo       |
 * | OPEN          | RECOVERED      | markRecovered()     | Inversión totalmente recuperada          |
 * | OPEN          | CLOSED         | close()             | Cierre manual (sin más actividad)        |
 * | OPEN          | CANCELLED      | cancel($reason)     | Cancelación voluntaria                   |
 * | PARTIAL       | RECOVERED      | markRecovered()     | Ventas acumulan recuperación total       |
 * | PARTIAL       | CLOSED         | close()             | Cierre manual                            |
 * | PARTIAL       | CANCELLED      | cancel($reason)     | Cancelación voluntaria                   |
 * | RECOVERED     | CLOSED         | close()             | Cierre tras recuperar                    |
 * | RECOVERED     | CANCELLED      | cancel($reason)     | Cancelación tras recuperar (raro)        |
 *
 * PROHIBIDO: Cualquier transición DESDE CANCELLED o CLOSED.
 * PROHIBIDO: Transición a OPEN desde cualquier estado.
 * PROHIBIDO: PARTIAL → OPEN, RECOVERED → OPEN, RECOVERED → PARTIAL.
 *
 * NOTA: La transición OPEN → RECOVERED es posible si en una sola venta
 * se recupera toda la inversión (modo INVESTMENT_FIRST) o todos los ítems
 * recuperan su costo (modo PER_PRODUCT).
 */
final class InvestmentStateMachine
{
    /**
     * Marca la inversión como PARCIAL (hay ventas, pero no recuperado todo).
     *
     * Transiciones válidas: OPEN → PARTIAL, PARTIAL → PARTIAL (idempotente)
     */
    public function markPartial(Investment $investment): void
    {
        $current = $investment->getStatus();

        if ($current === Investment::STATUS_CANCELLED || $current === Investment::STATUS_CLOSED) {
            throw InvalidStateTransitionException::fromTerminal($current, Investment::STATUS_PARTIAL);
        }

        if ($current === Investment::STATUS_RECOVERED) {
            throw InvalidStateTransitionException::invalid($current, Investment::STATUS_PARTIAL);
        }

        // OPEN o PARTIAL → PARTIAL
        if ($current !== Investment::STATUS_PARTIAL) {
            $investment->setStatus(Investment::STATUS_PARTIAL);
        }
    }

    /**
     * Marca la inversión como RECUPERADA (inversión totalmente recuperada).
     *
     * Transiciones válidas: OPEN → RECOVERED, PARTIAL → RECOVERED
     */
    public function markRecovered(Investment $investment): void
    {
        $current = $investment->getStatus();

        if ($current === Investment::STATUS_CANCELLED || $current === Investment::STATUS_CLOSED) {
            throw InvalidStateTransitionException::fromTerminal($current, Investment::STATUS_RECOVERED);
        }

        if ($current === Investment::STATUS_RECOVERED) {
            return; // Idempotente
        }

        // OPEN o PARTIAL → RECOVERED
        $investment->setStatus(Investment::STATUS_RECOVERED);
    }

    /**
     * Cierra la inversión (no más actividad esperada).
     *
     * Transiciones válidas: OPEN → CLOSED, PARTIAL → CLOSED, RECOVERED → CLOSED
     */
    public function close(Investment $investment): void
    {
        $current = $investment->getStatus();

        if ($current === Investment::STATUS_CANCELLED) {
            throw InvalidStateTransitionException::fromTerminal($current, Investment::STATUS_CLOSED);
        }

        if ($current === Investment::STATUS_CLOSED) {
            return; // Idempotente
        }

        // OPEN, PARTIAL, RECOVERED → CLOSED
        $investment->setStatus(Investment::STATUS_CLOSED);
        $investment->setClosedAt(new \DateTimeImmutable());
    }

    /**
     * Cancela la inversión (anulación voluntaria).
     *
     * Transiciones válidas: OPEN → CANCELLED, PARTIAL → CANCELLED, RECOVERED → CANCELLED
     *
     * @param string $reason Motivo de cancelación (requerido)
     */
    public function cancel(Investment $investment, string $reason): void
    {
        $current = $investment->getStatus();

        if ($current === Investment::STATUS_CANCELLED) {
            return; // Idempotente
        }

        if ($current === Investment::STATUS_CLOSED) {
            throw InvalidStateTransitionException::fromTerminal($current, Investment::STATUS_CANCELLED);
        }

        // OPEN, PARTIAL, RECOVERED → CANCELLED
        $investment->setStatus(Investment::STATUS_CANCELLED);
        $investment->setCancelledAt(new \DateTimeImmutable());
        $investment->setCancelledReason($reason);
    }

    /**
     * Verifica si una transición es válida (para UI, validaciones previas).
     */
    public function canTransition(string $from, string $to): bool
    {
        $terminal = [
            Investment::STATUS_CANCELLED,
            Investment::STATUS_CLOSED,
        ];

        if (in_array($from, $terminal, true)) {
            return false;
        }

        if ($from === Investment::STATUS_OPEN) {
            return in_array($to, [
                Investment::STATUS_PARTIAL,
                Investment::STATUS_RECOVERED,
                Investment::STATUS_CLOSED,
                Investment::STATUS_CANCELLED,
            ], true);
        }

        if ($from === Investment::STATUS_PARTIAL) {
            return in_array($to, [
                Investment::STATUS_RECOVERED,
                Investment::STATUS_CLOSED,
                Investment::STATUS_CANCELLED,
            ], true);
        }

        if ($from === Investment::STATUS_RECOVERED) {
            return in_array($to, [
                Investment::STATUS_CLOSED,
                Investment::STATUS_CANCELLED,
            ], true);
        }

        return false;
    }
}