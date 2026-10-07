<?php

declare(strict_types=1);

namespace App\Service\Money;

use InvalidArgumentException;

/**
 * Inmutable value object para cantidades monetarias.
 *
 * Usa bcmath exclusivamente. Nunca float.
 * Redondeo ROUND_HALF_UP a la escala configurada (por defecto 2 decimales).
 *
 * DECISIÓN: No se usa moneyphp/money ni brick/money (ADR-0001).
 *
 * @immutable
 *
 * EJEMPLOS MENTALMENTE VERIFICABLES:
 *   Money::of('100.00', 'ARS')->add(Money::of('50.00', 'ARS'))->getAmount() === '150.00'
 *   Money::of('100.00', 'ARS')->sub(Money::of('30.00', 'ARS'))->getAmount() === '70.00'
 *   Money::of('100.00', 'ARS')->mul('1.21')->getAmount() === '121.00'
 *   Money::of('121.00', 'ARS')->div('1.21')->getAmount() === '100.00'
 *   Money::of('100.00', 'ARS')->compare(Money::of('99.99', 'ARS')) === 1
 *   Money::of('0.00', 'ARS')->isZero() === true
 *   Money::of('-10.00', 'ARS')->isNegative() === true
 *   Money::of('10.00', 'ARS')->isPositive() === true
 *   Money::of('-10.00', 'ARS')->abs()->getAmount() === '10.00'
 *   Money::of('10.00', 'ARS')->negate()->getAmount() === '-10.00'
 *   Money::of('0.01', 'ARS')->div('2')->getAmount() === '0.01'     (HALF_UP: 0.005 → 0.01)
 *   Money::of('2.345', 'ARS')->getAmount() === '2.35'              (HALF_UP)
 *   Money::of('0.99', 'ARS', 0)->getAmount() === '1'               (HALF_UP escala 0)
 *   Money::of('.50', 'ARS')->getAmount() === '0.50'
 *   Money::of('-.50', 'ARS')->getAmount() === '-0.50'
 *   Money::of('10.00', 'ARS')->equals(Money::of('10.00', 'ARS')) === true
 *   Money::of('10.00', 'ARS')->equals(Money::of('10.01', 'ARS')) === false
 */
final class Money
{
    /** @var string Cantidad normalizada con escala fija (ej. '100.00') */
    private string $amount;

    /** @var string Código de moneda (ej. 'ARS', 'USD') */
    private string $currency;

    /** @var int Escala (decimales) usada para redondeo */
    private int $scale;

    private function __construct(string $amount, string $currency, int $scale = 2)
    {
        $this->amount = $amount;
        $this->currency = strtoupper($currency);
        $this->scale = $scale;
    }

    /**
     * Crea Money desde string, normalizando a la escala dada con HALF_UP.
     */
    public static function of(string $amount, string $currency, int $scale = 2): self
    {
        $normalized = self::normalizeAmount($amount, $scale);
        return new self($normalized, $currency, $scale);
    }

    /**
     * Cero en la moneda dada.
     */
    public static function zero(string $currency, int $scale = 2): self
    {
        return new self(self::padScale('0', $scale), strtoupper($currency), $scale);
    }

    private static function normalizeAmount(string $amount, int $scale): string
    {
        if (!self::isValidNumericString($amount)) {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" no es un número válido.',
                $amount
            ));
        }

        $clean = ltrim($amount, '+');
        if ($clean === '' || $clean === '.' || $clean === '-') {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" no es un número válido.',
                $amount
            ));
        }

        return self::roundHalfUp($clean, $scale);
    }

    /**
     * Valida formato numérico (entero o decimal, signo opcional).
     * Acepta: '100', '100.5', '100.50', '.50', '-.50', '-10.5'.
     * Rechaza: notación científica, comas, espacios.
     */
    private static function isValidNumericString(string $s): bool
    {
        return (bool)preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $s);
    }

    /**
     * Redondea HALF_UP a la escala objetivo.
     *
     * Estrategia: sumar 0.5 * 10^-scale y truncar.
     *   scale=2: '2.345' + '0.005' = '2.350' → truncar a 2 → '2.35'
     *   scale=2: '2.344' + '0.005' = '2.349' → truncar a 2 → '2.34'
     *   scale=0: '0.99'  + '0.5'   = '1.49'  → truncar a 0 → '1'
     *   scale=2: '0.01' / 2 = '0.005000' + '0.005' = '0.010000' → '0.01'
     */
    private static function roundHalfUp(string $amount, int $scale): string
    {
        $sign = str_starts_with($amount, '-') ? -1 : 1;
        $abs = ltrim($amount, '+-');

        $half = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        $adjusted = bcadd($abs, $half, $scale + 1);
        $truncated = bcadd($adjusted, '0', $scale);

        return ($sign < 0 && bccomp($truncated, '0', $scale) !== 0 ? '-' : '') . $truncated;
    }

    /**
     * Padding con ceros hasta la escala dada. Trunca si tiene más.
     * NO redondea; usar roundHalfUp antes.
     */
    private static function padScale(string $amount, int $scale): string
    {
        if ($scale === 0) {
            return explode('.', $amount)[0] ?? '0';
        }

        if (str_contains($amount, '.')) {
            [$intPart, $decPart] = explode('.', $amount);
            $decPart = str_pad($decPart, $scale, '0', STR_PAD_RIGHT);
            return $intPart . '.' . substr($decPart, 0, $scale);
        }

        return $amount . '.' . str_repeat('0', $scale);
    }

    public function getAmount(): string
    {
        return $this->amount;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getScale(): int
    {
        return $this->scale;
    }

    // ============================================================
    // OPERACIONES ARITMÉTICAS
    // ============================================================

    public function add(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self(
            bcadd($this->amount, $other->amount, $this->scale),
            $this->currency,
            $this->scale
        );
    }

    public function sub(Money $other): self
    {
        $this->assertSameCurrency($other);
        return new self(
            bcsub($this->amount, $other->amount, $this->scale),
            $this->currency,
            $this->scale
        );
    }

    /**
     * Multiplica por un factor. Aplica HALF_UP al resultado.
     */
    public function mul(string $factor): self
    {
        if (!self::isValidNumericString($factor)) {
            throw new InvalidArgumentException(sprintf('Factor "%s" inválido.', $factor));
        }
        // Escala interna +4 para preservar precisión intermedia, luego HALF_UP a escala final.
        $result = bcmul($this->amount, $factor, $this->scale + 4);
        return new self(self::roundHalfUp($result, $this->scale), $this->currency, $this->scale);
    }

    /**
     * Divide por un divisor. Aplica HALF_UP al resultado.
     */
    public function div(string $divisor): self
    {
        if (!self::isValidNumericString($divisor)) {
            throw new InvalidArgumentException(sprintf('Divisor "%s" inválido.', $divisor));
        }
        if (bccomp($divisor, '0', $this->scale) === 0) {
            throw new InvalidArgumentException('División por cero.');
        }
        // Escala interna +4 para preservar precisión intermedia, luego HALF_UP a escala final.
        $result = bcdiv($this->amount, $divisor, $this->scale + 4);
        return new self(self::roundHalfUp($result, $this->scale), $this->currency, $this->scale);
    }

    // ============================================================
    // COMPARACIÓN Y PREDICADOS
    // ============================================================

    /**
     * @return int -1 si $this < $other, 0 si igual, 1 si $this > $other
     */
    public function compare(Money $other): int
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->amount, $this->scale);
    }

    public function equals(Money $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function isZero(): bool
    {
        return bccomp($this->amount, '0', $this->scale) === 0;
    }

    public function isNegative(): bool
    {
        return bccomp($this->amount, '0', $this->scale) < 0;
    }

    public function isPositive(): bool
    {
        return bccomp($this->amount, '0', $this->scale) > 0;
    }

    // ============================================================
    // TRANSFORMACIONES
    // ============================================================

    public function abs(): self
    {
        if ($this->isNegative()) {
            return new self(substr($this->amount, 1), $this->currency, $this->scale);
        }
        return $this;
    }

    public function negate(): self
    {
        if ($this->isZero()) {
            return $this;
        }
        return new self(
            $this->isNegative() ? substr($this->amount, 1) : '-' . $this->amount,
            $this->currency,
            $this->scale
        );
    }

    // ============================================================
    // FORMATO Y SALIDA
    // ============================================================

    public function format(string $thousandsSep = '.', string $decimalSep = ','): string
    {
        [$intPart, $decPart] = explode('.', $this->amount);
        $sign = '';
        if (str_starts_with($intPart, '-')) {
            $sign = '-';
            $intPart = substr($intPart, 1);
        }

        $formattedInt = '';
        $len = strlen($intPart);
        for ($i = 0; $i < $len; ++$i) {
            if ($i > 0 && ($len - $i) % 3 === 0) {
                $formattedInt .= $thousandsSep;
            }
            $formattedInt .= $intPart[$i];
        }

        return $sign . $formattedInt . $decimalSep . $decPart . ' ' . $this->currency;
    }

    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    private function assertSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(sprintf(
                'Moneda incompatible: %s vs %s.',
                $this->currency,
                $other->currency
            ));
        }
        if ($this->scale !== $other->scale) {
            throw new InvalidArgumentException(sprintf(
                'Escala incompatible: %d vs %d.',
                $this->scale,
                $other->scale
            ));
        }
    }
}
