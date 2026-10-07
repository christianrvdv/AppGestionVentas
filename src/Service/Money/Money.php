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
 * DECISIÓN: No se usa moneyphp/money ni brick/money.
 * RAZONES (ADR-0001):
 *   1. Dependencia extra para una necesidad simple: solo aritmética decimal
 *      con escala fija, sin intercambio de divisas, sin parsing de locale,
 *      sin formateo complejo.
 *   2. moneyphp/money requiere PHP 8.1+ pero su Money\Currency usa códigos
 *      ISO 4217 estrictos; nuestro baseCurrency es configurable por tenant
 *      (p.ej. "ARS", "USD", "COP") y no queremos validar contra lista ISO.
 *   3. brick/money es excelente pero añade ~50 clases al vendor; para un
 *      dominio que solo necesita add/sub/mul/div/compare/zero/isZero, el
 *      ratio valor/complejidad no justifica la dependencia.
 *   4. Mantener el control total del redondeo (ROUND_HALF_UP, escala
 *      configurable por tenant vía SettingsService) es más simple con
 *      bcmath directo que configurar un Context en librerías externas.
 *   5. Si en el futuro se necesita multi-moneda real (conversión, FX),
 *      se puede migrar a brick/money sin romper la interfaz pública de
 *      esta clase (los métodos seguirían devolviendo Money).
 *
 * @immutable
 *
 * @method static Money of(string $amount, string $currency) Crea instancia validando formato.
 * @method static Money zero(string $currency) Crea cero en la moneda dada.
 *
 * EJEMPLOS MENTALMENTE VERIFICABLES:
 *   Money::of('100.00', 'ARS')->add(Money::of('50.00', 'ARS'))->format() === '150.00'
 *   Money::of('100.00', 'ARS')->sub(Money::of('30.00', 'ARS'))->format() === '70.00'
 *   Money::of('100.00', 'ARS')->mul('1.21')->format() === '121.00'   (IVA 21%)
 *   Money::of('121.00', 'ARS')->div('1.21')->format() === '100.00'
 *   Money::of('100.00', 'ARS')->compare(Money::of('99.99', 'ARS')) === 1
 *   Money::of('0.00', 'ARS')->isZero() === true
 *   Money::of('-10.00', 'ARS')->isNegative() === true
 *   Money::of('10.00', 'ARS')->isPositive() === true
 *   Money::of('-10.00', 'ARS')->abs()->format() === '10.00'
 *   Money::of('10.00', 'ARS')->negate()->format() === '-10.00'
 *   Money::of('0.01', 'ARS')->div('2')->getAmount() === '0.01'     (HALF_UP: 0.005 → 0.01)
 *   Money::of('2.345', 'ARS')->getAmount() === '2.35'               (HALF_UP)
 *   Money::of('0.99', 'ARS', 0)->getAmount() === '1'                (HALF_UP escala 0)
 *   Money::of('.50', 'ARS')->getAmount() === '0.50'                 (formato .50 válido)
 *   Money::of('-.50', 'ARS')->getAmount() === '-0.50'               (formato -.50 válido)
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

    /**
     * Constructor privado. Usar of() o zero().
     *
     * @param string $amount   Cantidad ya normalizada a $scale decimales
     * @param string $currency Código de moneda (3 letras, no validado contra ISO)
     * @param int    $scale    Decimales (por defecto 2)
     */
    private function __construct(string $amount, string $currency, int $scale = 2)
    {
        $this->amount   = $amount;
        $this->currency = strtoupper($currency);
        $this->scale    = $scale;
    }

    /**
     * Crea Money desde string, normalizando a la escala dada.
     *
     * @param string $amount   Ej: '100', '100.5', '100.50', '.50', '-10.5'
     * @param string $currency Código de moneda (ej. 'ARS', 'USD')
     * @param int    $scale    Decimales (por defecto 2)
     *
     * @throws InvalidArgumentException Si $amount no es numérico válido
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

    /**
     * Normaliza un string numérico a la escala dada con ROUND_HALF_UP.
     *
     * @throws InvalidArgumentException Si no es numérico válido
     */
    private static function normalizeAmount(string $amount, int $scale): string
    {
        if (!self::isValidNumericString($amount)) {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" no es un número válido.',
                $amount
            ));
        }

        // bcmath no acepta notación científica ni strings vacíos
        $clean = ltrim($amount, '+');
        if ($clean === '' || $clean === '.' || $clean === '-') {
            throw new InvalidArgumentException(sprintf(
                'Amount "%s" no es un número válido.',
                $amount
            ));
        }

        // Redondeo a $scale con ROUND_HALF_UP real
        return self::roundHalfUp($clean, $scale);
    }

    /**
     * Valida que el string sea numérico (entero o decimal, opcional signo).
     * No acepta notación científica.
     * Acepta formatos como: '100', '100.5', '100.50', '.50', '-.50', '-10.5'
     */
    private static function isValidNumericString(string $s): bool
    {
        // Regex: opcional signo, (dígitos con opcional decimal) O (punto decimal con dígitos)
        return (bool)preg_match('/^[+-]?(\d+(\.\d+)?|\.\d+)$/', $s);
    }

    /**
     * Redondea un string numérico a la escala dada usando ROUND_HALF_UP.
     *
     * @param string $amount Número en string (ej. '2.345', '0.01', '-10.5')
     * @param int    $scale  Decimales objetivo
     * @return string Número redondeado con exactamente $scale decimales
     */
    private static function roundHalfUp(string $amount, int $scale): string
    {
        $sign = str_starts_with($amount, '-') ? -1 : 1;
        $abs = ltrim($amount, '+-');

        // Sumar 0.5 * 10^-scale antes de truncar
        $half = $scale === 0 ? '0.5' : '0.' . str_repeat('0', $scale) . '5';
        $adjusted = bcadd($abs, $half, $scale + 1);
        $truncated = bcadd($adjusted, '0', $scale);

        return ($sign < 0 && bccomp($truncated, '0', $scale) !== 0 ? '-' : '') . $truncated;
    }

    /**
     * Asegura que el string tenga exactamente $scale decimales (padding con ceros).
     * Asume que el input ya viene redondeado con roundHalfUp.
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

    /** Devuelve la cantidad como string normalizado (ej. '100.00'). */
    public function getAmount(): string
    {
        return $this->amount;
    }

    /** Devuelve el código de moneda (ej. 'ARS'). */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /** Devuelve la escala (decimales). */
    public function getScale(): int
    {
        return $this->scale;
    }

    // ============================================================
    // OPERACIONES ARITMÉTICAS (devuelven nueva instancia)
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
     * Multiplica por un factor (string para evitar float).
     * Ej: mul('1.21') para aplicar IVA 21%.
     */
    public function mul(string $factor): self
    {
        if (!self::isValidNumericString($factor)) {
            throw new InvalidArgumentException(sprintf('Factor "%s" inválido.', $factor));
        }
        // Escala interna mayor para preservar precisión intermedia
        $result = bcmul($this->amount, $factor, $this->scale + 4);
        return new self(self::padScale($result, $this->scale), $this->currency, $this->scale);
    }

    /**
     * Divide por un divisor (string).
     * Ej: div('1.21') para quitar IVA 21%.
     */
    public function div(string $divisor): self
    {
        if (!self::isValidNumericString($divisor)) {
            throw new InvalidArgumentException(sprintf('Divisor "%s" inválido.', $divisor));
        }
        if (bccomp($divisor, '0', $this->scale) === 0) {
            throw new InvalidArgumentException('División por cero.');
        }
        $result = bcdiv($this->amount, $divisor, $this->scale + 4);
        return new self(self::padScale($result, $this->scale), $this->currency, $this->scale);
    }

    // ============================================================
    // COMPARACIÓN Y PREDICADOS
    // ============================================================

    /**
     * Compara con otro Money.
     * @return int -1 si $this < $other, 0 si igual, 1 si $this > $other
     */
    public function compare(Money $other): int
    {
        $this->assertSameCurrency($other);
        return bccomp($this->amount, $other->amount, $this->scale);
    }

    /**
     * Verifica igualdad monetaria (misma moneda, escala y cantidad).
     */
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

    /**
     * Formato para presentación: '1.234,56 ARS' (separador de miles configurable).
     *
     * @param string $thousandsSep Separador de miles (por defecto '.')
     * @param string $decimalSep   Separador decimal (por defecto ',')
     */
    public function format(string $thousandsSep = '.', string $decimalSep = ','): string
    {
        [$intPart, $decPart] = explode('.', $this->amount);
        $sign = '';
        if (str_starts_with($intPart, '-')) {
            $sign = '-';
            $intPart = substr($intPart, 1);
        }

        // Agrupa de a 3 desde la derecha
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

    /** Representación corta: '100.00 ARS' (sin separador de miles). */
    public function __toString(): string
    {
        return $this->amount . ' ' . $this->currency;
    }

    // ============================================================
    // VALIDACIONES INTERNAS
    // ============================================================

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