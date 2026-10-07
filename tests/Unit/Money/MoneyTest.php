<?php

namespace App\Tests\Unit\Money;

use App\Service\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    // ============================================================
    // CREATION & NORMALIZATION
    // ============================================================

    public function test_of_creates_money_with_two_decimals(): void
    {
        $money = Money::of('100', 'ARS');
        $this->assertSame('100.00', $money->getAmount());
        $this->assertSame('ARS', $money->getCurrency());
        $this->assertSame(2, $money->getScale());
    }

    public function test_of_preserves_one_decimal(): void
    {
        $money = Money::of('100.5', 'ARS');
        $this->assertSame('100.50', $money->getAmount());
    }

    public function test_of_accepts_leading_dot(): void
    {
        $money = Money::of('.50', 'ARS');
        $this->assertSame('0.50', $money->getAmount());
    }

    public function test_of_accepts_negative_leading_dot(): void
    {
        $money = Money::of('-.50', 'ARS');
        $this->assertSame('-0.50', $money->getAmount());
    }

    public function test_of_rounds_half_up_at_scale_two(): void
    {
        $money = Money::of('2.345', 'ARS');
        $this->assertSame('2.35', $money->getAmount());
    }

    public function test_of_rounds_down_when_below_half(): void
    {
        $money = Money::of('2.344', 'ARS');
        $this->assertSame('2.34', $money->getAmount());
    }

    public function test_of_rounds_half_up_at_scale_zero(): void
    {
        $money = Money::of('0.99', 'ARS', 0);
        $this->assertSame('1', $money->getAmount());
    }

    public function test_of_rejects_invalid_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('abc', 'ARS');
    }

    public function test_of_rejects_scientific_notation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('1e5', 'ARS');
    }

    public function test_of_rejects_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('', 'ARS');
    }

    public function test_of_rejects_just_sign(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('-', 'ARS');
    }

    public function test_zero_creates_zero_amount(): void
    {
        $money = Money::zero('ARS');
        $this->assertSame('0.00', $money->getAmount());
        $this->assertTrue($money->isZero());
    }

    // ============================================================
    // ARITHMETIC OPERATIONS
    // ============================================================

    public function test_add_returns_new_instance_with_sum(): void
    {
        $a = Money::of('100.00', 'ARS');
        $b = Money::of('50.00', 'ARS');
        $result = $a->add($b);

        $this->assertSame('150.00', $result->getAmount());
        $this->assertNotSame($a, $result); // inmutabilidad
    }

    public function test_sub_returns_new_instance_with_difference(): void
    {
        $a = Money::of('100.00', 'ARS');
        $b = Money::of('30.00', 'ARS');
        $result = $a->sub($b);

        $this->assertSame('70.00', $result->getAmount());
    }

    public function test_mul_returns_new_instance_with_product(): void
    {
        $money = Money::of('100.00', 'ARS');
        $result = $money->mul('1.21');

        $this->assertSame('121.00', $result->getAmount());
    }

    public function test_div_returns_new_instance_with_quotient(): void
    {
        $money = Money::of('121.00', 'ARS');
        $result = $money->div('1.21');

        $this->assertSame('100.00', $result->getAmount());
    }

    public function test_div_by_zero_throws(): void
    {
        $money = Money::of('100.00', 'ARS');
        $this->expectException(InvalidArgumentException::class);
        $money->div('0');
    }

    public function test_add_different_currency_throws(): void
    {
        $ars = Money::of('100.00', 'ARS');
        $usd = Money::of('50.00', 'USD');
        $this->expectException(InvalidArgumentException::class);
        $ars->add($usd);
    }

    public function test_add_different_scale_throws(): void
    {
        $a = Money::of('100.00', 'ARS', 2);
        $b = Money::of('50.00', 'ARS', 4);
        $this->expectException(InvalidArgumentException::class);
        $a->add($b);
    }

    public function test_div_does_not_truncate_below_scale(): void
    {
        // 0.01 / 2 = 0.005 -> redondea a 0.01 (HALF_UP)
        $money = Money::of('0.01', 'ARS');
        $result = $money->div('2');

        $this->assertSame('0.01', $result->getAmount());
    }

    // ============================================================
    // COMPARISON & PREDICATES
    // ============================================================

    public function test_compare_returns_1_when_greater(): void
    {
        $a = Money::of('100.00', 'ARS');
        $b = Money::of('99.99', 'ARS');
        $this->assertSame(1, $a->compare($b));
    }

    public function test_compare_returns_0_when_equal(): void
    {
        $a = Money::of('100.00', 'ARS');
        $b = Money::of('100.00', 'ARS');
        $this->assertSame(0, $a->compare($b));
    }

    public function test_compare_returns_minus_1_when_less(): void
    {
        $a = Money::of('99.99', 'ARS');
        $b = Money::of('100.00', 'ARS');
        $this->assertSame(-1, $a->compare($b));
    }

    public function test_equals_returns_true_for_same_amount_currency_scale(): void
    {
        $a = Money::of('10.00', 'ARS');
        $b = Money::of('10.00', 'ARS');
        $this->assertTrue($a->equals($b));
    }

    public function test_equals_returns_false_for_different_amount(): void
    {
        $a = Money::of('10.00', 'ARS');
        $b = Money::of('10.01', 'ARS');
        $this->assertFalse($a->equals($b));
    }

    public function test_is_zero_returns_true_for_zero(): void
    {
        $money = Money::of('0.00', 'ARS');
        $this->assertTrue($money->isZero());
    }

    public function test_is_zero_returns_false_for_non_zero(): void
    {
        $money = Money::of('0.01', 'ARS');
        $this->assertFalse($money->isZero());
    }

    public function test_is_negative_returns_true_for_negative(): void
    {
        $money = Money::of('-10.00', 'ARS');
        $this->assertTrue($money->isNegative());
    }

    public function test_is_negative_returns_false_for_positive(): void
    {
        $money = Money::of('10.00', 'ARS');
        $this->assertFalse($money->isNegative());
    }

    public function test_is_positive_returns_true_for_positive(): void
    {
        $money = Money::of('10.00', 'ARS');
        $this->assertTrue($money->isPositive());
    }

    public function test_is_positive_returns_false_for_negative(): void
    {
        $money = Money::of('-10.00', 'ARS');
        $this->assertFalse($money->isPositive());
    }

    // ============================================================
    // TRANSFORMATIONS
    // ============================================================

    public function test_abs_returns_positive_value(): void
    {
        $money = Money::of('-10.00', 'ARS');
        $result = $money->abs();

        $this->assertSame('10.00', $result->getAmount());
        $this->assertFalse($result->isNegative());
    }

    public function test_abs_returns_same_instance_when_already_positive(): void
    {
        $money = Money::of('10.00', 'ARS');
        $result = $money->abs();

        $this->assertSame($money, $result);
    }

    public function test_negate_returns_negative_value(): void
    {
        $money = Money::of('10.00', 'ARS');
        $result = $money->negate();

        $this->assertSame('-10.00', $result->getAmount());
    }

    public function test_negate_returns_same_instance_when_zero(): void
    {
        $money = Money::zero('ARS');
        $result = $money->negate();

        $this->assertSame($money, $result);
    }

    // ============================================================
    // FORMATTING
    // ============================================================

    public function test_format_returns_formatted_string_with_default_separators(): void
    {
        $money = Money::of('100.00', 'ARS');
        $this->assertSame('100,00 ARS', $money->format());
    }

    public function test_format_adds_thousands_separator(): void
    {
        $money = Money::of('1234.56', 'ARS');
        $this->assertSame('1.234,56 ARS', $money->format());
    }

    public function test_format_preserves_negative_sign(): void
    {
        $money = Money::of('-1234.56', 'ARS');
        $this->assertSame('-1.234,56 ARS', $money->format());
    }

    public function test_format_allows_custom_separators(): void
    {
        $money = Money::of('1234.56', 'ARS');
        $this->assertSame('1,234.56 ARS', $money->format(',', '.'));
    }

    public function test_to_string_returns_short_format(): void
    {
        $money = Money::of('100.00', 'ARS');
        $this->assertSame('100.00 ARS', (string)$money);
    }
}