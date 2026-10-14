<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\DTO\Command\PaymentCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Payment;
use App\Entity\PaymentAllocation;
use App\Entity\Sale;
use App\Exception\Domain\CurrencyMismatchException;
use App\Exception\Domain\CustomerMismatchException;
use App\Exception\Domain\PaymentAllocationException;
use App\Exception\Domain\TenantMismatchException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\PaymentAllocationRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\ActiveTenantProvider;
use App\Service\UsdRateService;

/**
 * Registro de pagos (a ventas o anticipos de clientes) y aplicación de
 * anticipos a ventas (Fase 3f, ADR-0018).
 *
 * REGLAS DE register():
 *   - Cliente OPCIONAL [FIX-7, Estrategia B]: customerId null = pago de
 *     mostrador / sin cliente registrado (el dominio ya contempla ventas
 *     sin cliente). Antes se pasaba null a findByIdAndTenant(int, int)
 *     y reventaba con TypeError.
 *   - Si sale está seteada Y el pago define cliente: valida que
 *     customer == sale.customer. Si alguno de los dos es null no se
 *     compara (la venta de mostrador tampoco tiene cliente).
 *   - Setea currency desde investment.baseCurrency
 *   - Snapshot de usdRateSnapshot
 *   - Método: CASH, TRANSFER, CARD, OTHER
 *   - Si hay venta, crea la PaymentAllocation equivalente: desde la
 *     Fase 3f el vínculo pago↔venta es esa tabla, no Payment.sale.
 *
 * REGLAS DE applyToSale():
 *   - El pago y la venta son del tenant activo y de la misma moneda.
 *   - Si la venta tiene cliente, el pago también y coincide (FIX-7).
 *   - El monto no supera el remanente del pago ni el pendiente de la venta.
 *
 * FLUSH (ADR-0017): ninguno de los dos métodos flushea. Ambos solo
 * leen con SQL (sumas de allocations) y devuelven entidades sin persistir;
 * el llamador hace el único flush, igual que hacía con register().
 */
final class PaymentService
{
    /** Métodos de pago válidos. */
    public const METHOD_CASH = Payment::METHOD_CASH;
    public const METHOD_TRANSFER = Payment::METHOD_TRANSFER;
    public const METHOD_CARD = Payment::METHOD_CARD;
    public const METHOD_OTHER = Payment::METHOD_OTHER;

    public function __construct(
        private readonly InvestmentRepositoryInterface $investmentRepository,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly SaleRepositoryInterface $saleRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentAllocationRepositoryInterface $allocationRepository,
        private readonly ActiveTenantProvider $activeTenantProvider,
        private readonly UsdRateService $usdRateService
    )
    {
    }

    /**
     * Registra un pago.
     *
     * @param PaymentCommand $cmd Comando inmutable con datos del pago
     * @param AppUser $user Usuario que registra
     *
     * @return Payment Entidad lista para persistir
     *
     * @throws \LogicException            Si inversión/cliente/venta no encontrados
     * @throws CurrencyMismatchException  Si cliente de pago != cliente de venta
     */
    public function register(PaymentCommand $cmd, AppUser $user): Payment
    {
        // 0. Tenant activo de la sesión (ADR-0015): el usuario es global,
        //    el negocio no se deduce de él sino del tenant activo.
        $tenantId = $this->activeTenantProvider->requireTenant()->getId();

        // 1. Obtener inversión (para moneda base)
        $investment = $this->investmentRepository->findByIdAndTenant($cmd->investmentId, $tenantId);
        if ($investment === null) {
            throw new \LogicException(sprintf('Inversión %d no encontrada.', $cmd->investmentId));
        }

        // 2. Cliente (OPCIONAL) [FIX-7] Estrategia B: pago de mostrador.
        //    Si customerId es null NO se consulta el repositorio (antes se
        //    pasaba null a findByIdAndTenant(int, int) → TypeError en runtime).
        $customer = null;
        if ($cmd->customerId !== null) {
            $customer = $this->customerRepository->findByIdAndTenant($cmd->customerId, $tenantId);
            if ($customer === null) {
                throw new \LogicException(sprintf('Cliente %d no encontrado.', $cmd->customerId));
            }
            // C.6: Validar que el cliente pertenece al mismo tenant que la inversión
            if ($customer->getTenant()->getId() !== $investment->getTenant()->getId()) {
                throw TenantMismatchException::forEntities(
                    'Customer',
                    $customer->getTenant()->getId(),
                    $investment->getTenant()->getId()
                );
            }
        }

        // 3. Validar venta si está seteada
        $sale = null;
        if ($cmd->saleId !== null) {
            $sale = $this->saleRepository->findByIdAndTenant($cmd->saleId, $tenantId);
            if ($sale === null) {
                throw new \LogicException(sprintf('Venta %d no encontrada.', $cmd->saleId));
            }

            // Validar que la venta pertenece al mismo tenant
            if ($sale->getTenant()->getId() !== $investment->getTenant()->getId()) {
                throw TenantMismatchException::forEntities(
                    'Sale',
                    $sale->getTenant()->getId(),
                    $investment->getTenant()->getId()
                );
            }

            // [FIX-7] Validar que el cliente coincida SOLO cuando ambos están
            // definidos: una venta de mostrador no tiene cliente y un pago sin
            // cliente no puede compararse con el de la venta.
            if ($customer !== null && $sale->getCustomer() !== null && $sale->getCustomer()->getId() !== $customer->getId()) {
                throw CustomerMismatchException::forSale(
                    $sale->getId(),
                    $sale->getCustomer()->getId(),
                    $customer->getId()
                );
            }
        }

        // 4. Moneda: base de la inversión
        $currency = $investment->getBaseCurrency();

        // 5. Tasa USD actual
        $currentUsdRate = $this->usdRateService->getCurrent();
        $usdRateSnapshot = $currentUsdRate?->getRate();

        // 6. Validar método
        $validMethods = [
            self::METHOD_CASH,
            self::METHOD_TRANSFER,
            self::METHOD_CARD,
            self::METHOD_OTHER,
        ];
        if (!in_array($cmd->method, $validMethods, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Método inválido: %s. Válidos: %s',
                $cmd->method,
                implode(', ', $validMethods)
            ));
        }

        // 7. Crear Payment
        $payment = new Payment();
        $payment->setTenant($this->activeTenantProvider->requireTenant());
        $payment->setCustomer($customer);
        $payment->setAmount($cmd->amount);
        $payment->setCurrency($currency);
        $payment->setUsdRateSnapshot($usdRateSnapshot);
        $payment->setPaymentDate($cmd->date);
        $payment->setMethod($cmd->method);
        $payment->setReference($cmd->reference);
        $payment->setNotes($cmd->notes);

        // 8. Si el pago es contra una venta, el vínculo es una allocation
        //    (Fase 3f): Payment.sale dejó de existir. El importe aplicado es
        //    el total del pago, igual que antes lo era Payment.sale_id.
        if ($sale !== null) {
            $payment->addAllocation($this->createAllocation($payment, $sale, $cmd->amount));
        }

        return $payment;
    }

    /**
     * Aplica un anticipo (o cualquier pago con remanente) a una venta.
     *
     * El monto es un string de 2 decimales. No persiste ni flushea: devuelve
     * la allocation y el llamador hace el flush (padrón de register()).
     *
     * @throws PaymentAllocationException  Si el monto no es positivo, supera el
     *                                     remanente del pago o el pendiente de
     *                                     la venta, la venta está anulada, o el
     *                                     pago ya cubre esa venta
     * @throws TenantMismatchException     Si pago o venta son de otro negocio
     * @throws CurrencyMismatchException   Si las monedas no coinciden
     * @throws CustomerMismatchException   Si la venta tiene cliente y el pago
     *                                     no, o no coinciden (FIX-7)
     */
    public function applyToSale(Payment $payment, Sale $sale, string $amount): PaymentAllocation
    {
        $tenantId = $this->activeTenantProvider->requireTenant()->getId();

        // 0. Mismo tenant. El controller ya resuelve ambas entidades con
        //    findByIdAndTenant, pero el servicio se valida solo: también se
        //    llama desde tests y podría llamarse desde otra entrada.
        if ($payment->getTenant()->getId() !== $tenantId) {
            throw TenantMismatchException::forEntities('Payment', $payment->getTenant()->getId(), $tenantId);
        }
        if ($sale->getTenant()->getId() !== $tenantId) {
            throw TenantMismatchException::forEntities('Sale', $sale->getTenant()->getId(), $tenantId);
        }

        // 1. Una venta anulada no admite cobros: SaleVoidService garantiza
        //    que ninguna tiene allocations, así que esto no debería ocurrir,
        //    pero la URL se puede escribir a mano.
        if ($sale->isVoided()) {
            throw PaymentAllocationException::voidedSale($sale->getId());
        }

        // 2. Moneda: Payment.currency nace de la inversión del pago y
        //    Sale.currency de la de la venta; si difieren, sumarlas corrompe
        //    el saldo.
        if ($payment->getCurrency() !== $sale->getCurrency()) {
            throw CurrencyMismatchException::expectedVsActual($sale->getCurrency(), $payment->getCurrency());
        }

        // 3. Cliente (FIX-7): solo se compara cuando ambos están definidos,
        //    igual que en register(). La venta de mostrador no tiene cliente
        //    contra quien comparar.
        $saleCustomer = $sale->getCustomer();
        $paymentCustomer = $payment->getCustomer();
        if ($saleCustomer !== null && $paymentCustomer !== null && $paymentCustomer->getId() !== $saleCustomer->getId()) {
            throw CustomerMismatchException::forSale($sale->getId(), $saleCustomer->getId(), $paymentCustomer->getId());
        }

        // 4. Monto > 0. El regex va primero: bccomp sobre un texto no
        //    numérico lanza ValueError en vez de un error de dominio.
        if (!preg_match('/^[+-]?\d+(\.\d+)?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw PaymentAllocationException::nonPositiveAmount();
        }

        // 5. Cada anticipo cubre cada venta UNA sola vez. La unique
        //    (payment_id, sale_id) lo garantiza en la base, pero la regla
        //    es de negocio: sin esta consulta el doble submit del form
        //    reventaría como violación de índice (500) en vez de un flash.
        if ($this->allocationRepository->hasAllocationForSale($payment->getId(), $sale->getId(), $tenantId)) {
            throw PaymentAllocationException::alreadyAppliedToSale($payment->getId(), $sale->getId());
        }

        // 6. Remanente del pago: amount − Σ allocations. Y pendiente de la
        //    venta: totalAmount − Σ allocations. Las dos sumas se leen con
        //    SQL, por eso el flush es responsabilidad del llamador (ADR-0017).
        $available = bcsub($payment->getAmount(), $this->allocationRepository->sumByPayment($payment->getId(), $tenantId), 2);
        if (bccomp($amount, $available, 2) > 0) {
            throw PaymentAllocationException::exceedsPaymentAvailable($amount, $available);
        }

        $pending = bcsub($sale->getTotalAmount(), $this->paymentRepository->sumBySale($sale->getId(), $tenantId), 2);
        if (bccomp($amount, $pending, 2) > 0) {
            throw PaymentAllocationException::exceedsSalePending($amount, $pending);
        }

        $allocation = $this->createAllocation($payment, $sale, $amount);
        $payment->addAllocation($allocation);

        return $allocation;
    }

    private function createAllocation(Payment $payment, Sale $sale, string $amount): PaymentAllocation
    {
        $allocation = new PaymentAllocation();
        $allocation->setPayment($payment);
        $allocation->setSale($sale);
        $allocation->setAmount($amount);

        return $allocation;
    }
}
