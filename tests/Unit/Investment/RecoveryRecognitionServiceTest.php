<?php

namespace App\Tests\Unit\Investment;

use App\Entity\Investment;
use App\Entity\Tenant;
use App\Service\Investment\RecoveryRecognitionService;
use PHPUnit\Framework\TestCase;

final class RecoveryRecognitionServiceTest extends TestCase
{
    private RecoveryRecognitionService $service;

    protected function setUp(): void
    {
        $this->service = new RecoveryRecognitionService();
    }

    public function test_per_product_positive_gross_profit_recognizes_full(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_PER_PRODUCT);
        $recognized = $this->service->recognize($investment, '100.00', '500.00', '0.00');
        $this->assertSame('100.00', $recognized);
    }

    public function test_per_product_zero_gross_profit_recognizes_zero(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_PER_PRODUCT);
        $recognized = $this->service->recognize($investment, '0.00', '500.00', '0.00');
        $this->assertSame('0.00', $recognized);
    }

    public function test_per_product_negative_gross_profit_recognizes_zero(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_PER_PRODUCT);
        $recognized = $this->service->recognize($investment, '-50.00', '500.00', '0.00');
        $this->assertSame('0.00', $recognized);
    }

    public function test_investment_first_accumulated_below_total_recognizes_zero(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $recognized = $this->service->recognize($investment, '100.00', '500.00', '0.00');
        $this->assertSame('0.00', $recognized);
    }

    public function test_investment_first_excess_below_gross_profit_recognizes_excess(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $recognized = $this->service->recognize($investment, '150.00', '200.00', '900.00');
        $this->assertSame('100.00', $recognized);
    }

    public function test_investment_first_excess_above_gross_profit_recognizes_cap(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $recognized = $this->service->recognize($investment, '50.00', '200.00', '900.00');
        $this->assertSame('50.00', $recognized);
    }

    public function test_investment_first_negative_gross_profit_recognizes_zero(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $recognized = $this->service->recognize($investment, '-50.00', '200.00', '900.00');
        $this->assertSame('0.00', $recognized);
    }

    public function test_compute_from_lines_per_product_calculates_independently(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_PER_PRODUCT, '1000.00');
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '100.00'],
            ['costRecovered' => '300.00', 'grossProfit' => '50.00', 'recognizedProfit' => '50.00'],
            ['costRecovered' => '200.00', 'grossProfit' => '-20.00', 'recognizedProfit' => '0.00'],
        ];

        $result = $this->service->computeFromLines($investment, $lines);

        $this->assertSame('1000.00', $result['totalRecoveredPerProduct']);
        $this->assertSame('1000.00', $result['totalRecoveredInvestmentFirst']);
        $this->assertSame('150.00', $result['totalProfitPerProduct']);
        $this->assertSame('0.00', $result['totalProfitInvestmentFirst']);
        $this->assertSame('130.00', $result['totalGrossProfit']);
        $this->assertSame('150.00', $result['totalRecognizedProfit']);
    }

    public function test_compute_from_lines_investment_first_calculates_independently(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '400.00', 'grossProfit' => '80.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '200.00', 'grossProfit' => '50.00', 'recognizedProfit' => '100.00'],
        ];

        $result = $this->service->computeFromLines($investment, $lines);

        $this->assertSame('1100.00', $result['totalRecoveredInvestmentFirst']);
        $this->assertSame('50.00', $result['totalProfitInvestmentFirst']);
        $this->assertSame('230.00', $result['totalGrossProfit']);
        $this->assertSame('100.00', $result['totalRecognizedProfit']);
    }

    public function test_total_profit_per_product_ignores_recognized_profit_stored(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
        ];

        $result = $this->service->computeFromLines($investment, $lines);

        $this->assertSame('200.00', $result['totalProfitPerProduct']);
    }

    public function test_total_profit_per_product_excludes_negative_gross_profit(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_PER_PRODUCT, '1000.00');
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '100.00'],
            ['costRecovered' => '300.00', 'grossProfit' => '-50.00', 'recognizedProfit' => '0.00'],
        ];

        $result = $this->service->computeFromLines($investment, $lines);

        $this->assertSame('100.00', $result['totalProfitPerProduct']);
    }

    public function test_total_profit_investment_first_calculated_independently(): void
    {
        $investment = $this->createInvestment(Investment::RECOVERY_INVESTMENT_FIRST, '1000.00');
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '50.00'],
        ];

        $result = $this->service->computeFromLines($investment, $lines);

        $this->assertSame('1000.00', $result['totalRecoveredInvestmentFirst']);
        $this->assertSame('0.00', $result['totalProfitInvestmentFirst']);
    }

    public function test_compute_from_lines_with_custom_total_recalculates_with_new_total(): void
    {
        $customTotal = '1200.00';
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '200.00', 'grossProfit' => '50.00', 'recognizedProfit' => '0.00'],
        ];

        $result = $this->service->computeFromLinesWithCustomTotal($customTotal, $lines);

        $this->assertSame('1200.00', $result['totalRecoveredInvestmentFirst']);
        $this->assertSame('0.00', $result['totalProfitInvestmentFirst']);
    }

    public function test_compute_from_lines_with_custom_total_higher_total_allows_more_profit(): void
    {
        $customTotal = '900.00';
        $lines = [
            ['costRecovered' => '500.00', 'grossProfit' => '100.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '400.00', 'grossProfit' => '80.00', 'recognizedProfit' => '0.00'],
            ['costRecovered' => '100.00', 'grossProfit' => '20.00', 'recognizedProfit' => '100.00'],
        ];

        $result = $this->service->computeFromLinesWithCustomTotal($customTotal, $lines);

        $this->assertSame('1000.00', $result['totalRecoveredInvestmentFirst']);
        $this->assertSame('20.00', $result['totalProfitInvestmentFirst']);
    }

    private function createInvestment(string $recoveryMode, string $totalInvestment = '1000.00'): Investment
    {
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setSlug('test-tenant');

        $investment = new Investment();
        $investment->setTenant($tenant);
        $investment->setCode('TEST-001');
        $investment->setInvestmentDate(new \DateTimeImmutable());
        $investment->setBaseCurrency('ARS');

        $reflection = new \ReflectionClass($investment);

        $recoveryModeProp = $reflection->getProperty('recoveryMode');
        $recoveryModeProp->setAccessible(true);
        $recoveryModeProp->setValue($investment, $recoveryMode);

        $totalInvProp = $reflection->getProperty('totalInvestment');
        $totalInvProp->setAccessible(true);
        $totalInvProp->setValue($investment, $totalInvestment);

        return $investment;
    }
}