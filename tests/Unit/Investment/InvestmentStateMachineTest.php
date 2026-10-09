<?php

namespace App\Tests\Unit\Investment;

use App\Entity\Investment;
use App\Entity\Tenant;
use App\Entity\AppUser;
use App\Service\Investment\InvestmentStateMachine;
use App\Exception\Domain\InvalidStateTransitionException;
use PHPUnit\Framework\TestCase;

final class InvestmentStateMachineTest extends TestCase
{
    private InvestmentStateMachine $stateMachine;

    protected function setUp(): void
    {
        $this->stateMachine = new InvestmentStateMachine();
    }

    public function test_open_to_partial_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->stateMachine->markPartial($investment);
        $this->assertSame(Investment::STATUS_PARTIAL, $investment->getStatus());
    }

    public function test_open_to_recovered_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->stateMachine->markRecovered($investment);
        $this->assertSame(Investment::STATUS_RECOVERED, $investment->getStatus());
    }

    public function test_open_to_closed_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->stateMachine->close($investment);
        $this->assertSame(Investment::STATUS_CLOSED, $investment->getStatus());
        $this->assertNotNull($investment->getClosedAt());
    }

    public function test_open_to_cancelled_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->stateMachine->cancel($investment, 'Test reason');
        $this->assertSame(Investment::STATUS_CANCELLED, $investment->getStatus());
        $this->assertNotNull($investment->getCancelledAt());
        $this->assertSame('Test reason', $investment->getCancelledReason());
    }

    public function test_partial_to_recovered_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_PARTIAL);
        $this->stateMachine->markRecovered($investment);
        $this->assertSame(Investment::STATUS_RECOVERED, $investment->getStatus());
    }

    public function test_partial_to_closed_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_PARTIAL);
        $this->stateMachine->close($investment);
        $this->assertSame(Investment::STATUS_CLOSED, $investment->getStatus());
    }

    public function test_partial_to_cancelled_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_PARTIAL);
        $this->stateMachine->cancel($investment, 'Test reason');
        $this->assertSame(Investment::STATUS_CANCELLED, $investment->getStatus());
    }

    public function test_recovered_to_closed_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_RECOVERED);
        $this->stateMachine->close($investment);
        $this->assertSame(Investment::STATUS_CLOSED, $investment->getStatus());
    }

    public function test_recovered_to_cancelled_allowed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_RECOVERED);
        $this->stateMachine->cancel($investment, 'Test reason');
        $this->assertSame(Investment::STATUS_CANCELLED, $investment->getStatus());
    }

    public function test_cancelled_to_any_forbidden(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_CANCELLED);
        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->markPartial($investment);
    }

    public function test_closed_to_any_forbidden(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_CLOSED);
        $this->expectException(InvalidStateTransitionException::class);
        $this->stateMachine->markPartial($investment);
    }

    public function test_partial_to_open_forbidden(): void
    {
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_OPEN));
    }

    public function test_recovered_to_partial_forbidden(): void
    {
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_PARTIAL));
    }

    public function test_recovered_to_open_forbidden(): void
    {
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_OPEN));
    }

    public function test_mark_partial_idempotent_on_partial(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_PARTIAL);
        $this->stateMachine->markPartial($investment);
        $this->stateMachine->markPartial($investment);
        $this->assertSame(Investment::STATUS_PARTIAL, $investment->getStatus());
    }

    public function test_mark_recovered_idempotent_on_recovered(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_RECOVERED);
        $this->stateMachine->markRecovered($investment);
        $this->stateMachine->markRecovered($investment);
        $this->assertSame(Investment::STATUS_RECOVERED, $investment->getStatus());
    }

    public function test_close_idempotent_on_closed(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_CLOSED);
        $this->stateMachine->close($investment);
        $this->stateMachine->close($investment);
        $this->assertSame(Investment::STATUS_CLOSED, $investment->getStatus());
    }

    /**
     * [FIX] Antes arrancaba desde CANCELLED sin reason (estado inconsistente,
     * imposible en producción gracias a Investment::assertStatusConsistency()).
     * Ahora arranca desde OPEN y cancela dos veces, verificando que la segunda
     * llamada NO sobreescribe el motivo original.
     */
    public function test_cancel_idempotent_on_cancelled(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->stateMachine->cancel($investment, 'Original');
        $this->stateMachine->cancel($investment, 'Second');
        $this->assertSame(Investment::STATUS_CANCELLED, $investment->getStatus());
        $this->assertSame('Original', $investment->getCancelledReason());
    }

    public function test_close_sets_closed_at(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->assertNull($investment->getClosedAt());
        $this->stateMachine->close($investment);
        $this->assertNotNull($investment->getClosedAt());
    }

    public function test_cancel_sets_cancelled_at_and_reason(): void
    {
        $investment = $this->createInvestment(Investment::STATUS_OPEN);
        $this->assertNull($investment->getCancelledAt());
        $this->assertNull($investment->getCancelledReason());
        $this->stateMachine->cancel($investment, 'Motivo de prueba');
        $this->assertNotNull($investment->getCancelledAt());
        $this->assertSame('Motivo de prueba', $investment->getCancelledReason());
    }

    public function test_can_transition_from_open(): void
    {
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_OPEN, Investment::STATUS_PARTIAL));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_OPEN, Investment::STATUS_RECOVERED));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_OPEN, Investment::STATUS_CLOSED));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_OPEN, Investment::STATUS_CANCELLED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_OPEN, Investment::STATUS_OPEN));
    }

    public function test_can_transition_from_partial(): void
    {
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_RECOVERED));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_CLOSED));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_CANCELLED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_OPEN));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_PARTIAL, Investment::STATUS_PARTIAL));
    }

    public function test_can_transition_from_recovered(): void
    {
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_CLOSED));
        $this->assertTrue($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_CANCELLED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_OPEN));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_PARTIAL));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_RECOVERED, Investment::STATUS_RECOVERED));
    }

    public function test_can_transition_from_cancelled_returns_false(): void
    {
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CANCELLED, Investment::STATUS_OPEN));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CANCELLED, Investment::STATUS_PARTIAL));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CANCELLED, Investment::STATUS_RECOVERED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CANCELLED, Investment::STATUS_CLOSED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CANCELLED, Investment::STATUS_CANCELLED));
    }

    public function test_can_transition_from_closed_returns_false(): void
    {
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CLOSED, Investment::STATUS_OPEN));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CLOSED, Investment::STATUS_PARTIAL));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CLOSED, Investment::STATUS_RECOVERED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CLOSED, Investment::STATUS_CLOSED));
        $this->assertFalse($this->stateMachine->canTransition(Investment::STATUS_CLOSED, Investment::STATUS_CANCELLED));
    }

    private function createInvestment(string $status): Investment
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setSlug('test-tenant');

        $user = new AppUser();
        $user->setEmail('test@example.com');
        $user->setPasswordHash('hash');

        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCreatedBy($user);
        $investment->setCode('TEST-001');
        $investment->setInvestmentDate(new \DateTimeImmutable());
        $investment->setBaseCurrency('ARS');

        $reflection = new \ReflectionClass($investment);
        $property = $reflection->getProperty('status');
        $property->setAccessible(true);
        $property->setValue($investment, $status);

        return $investment;
    }
}
