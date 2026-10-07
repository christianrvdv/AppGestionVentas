<?php

declare(strict_types=1);

namespace App\Service\Payment;

use App\DTO\Command\PaymentCommand;
use App\Entity\AppUser;
use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Payment;
use App\Entity\Sale;
use App\Exception\Domain\CurrencyMismatchException;
use App\Exception\Domain\CustomerMismatchException;
use App\Exception\Domain\TenantMismatchException;
use App\Repository\Contract\CustomerRepositoryInterface;
use App\Repository\Contract\InvestmentRepositoryInterface;
use App\Repository\Contract\PaymentRepositoryInterface;
use App\Repository\Contract\SaleRepositoryInterface;
use App\Service\SettingsService;
use App\Service\UsdRateService;

/**
 * Registro de pagos (a ventas o anticipos de clientes).
 *
 * REGLAS:
 *   - Si sale está seteado: valida que customer == sale.customer
 *   - Setea currency desde investment.baseCurrency o settings.baseCurrency
 *   - Snapshot de usdRateSnapshot
 *   - Método: CASH, TRANSFER, CARD, OTHER
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
        private readonly CustomerRepositoryInterface   $customerRepository,
        private readonly SaleRepositoryInterface       $saleRepository,
        private readonly PaymentRepositoryInterface    $paymentRepository,
        private readonly SettingsService               $settingsService,
        private readonly UsdRateService                $usdRateService
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
        // 1. Obtener inversión (para moneda base)
        $investment = $this->investmentRepository->findByIdAndTenant($cmd->investmentId, $user->getTenant()->getId());
        if ($investment === null) {
            throw new \LogicException(sprintf('Inversión %d no encontrada.', $cmd->investmentId));
        }

        // 2. Obtener cliente
        $customer = $this->customerRepository->findByIdAndTenant($cmd->customerId, $user->getTenant()->getId());
        if ($customer === null) {
            throw new \LogicException(sprintf('Cliente %d no encontrado.', $cmd->customerId));
        }
        // C.6: Validar que el cliente pertenece al mismo tenant que la inversión
        if ($customer->getTenant()->getId() !== $investment->getTenant()->getId()) {
            throw TenantMismatchException::forEntities(
                'Customer',
                $investment->getTenant()->getId(),
                $customer->getTenant()->getId()
            );
        }

        // 3. Validar venta si está seteada
        $sale = null;
        if ($cmd->saleId !== null) {
            $sale = $this->saleRepository->findByIdAndTenant($cmd->saleId, $user->getTenant()->getId());
            if ($sale === null) {
                throw new \LogicException(sprintf('Venta %d no encontrada.', $cmd->saleId));
            }

            // Validar que la venta pertenece al mismo tenant
            if ($sale->getTenant()->getId() !== $investment->getTenant()->getId()) {
                throw TenantMismatchException::forEntities(
                    'Sale',
                    $investment->getTenant()->getId(),
                    $sale->getTenant()->getId()
                );
            }

            // Validar que el cliente coincida
            if ($sale->getCustomer() === null || $sale->getCustomer()->getId() !== $customer->getId()) {
                throw CustomerMismatchException::forSale(
                    $sale->getId(),
                    $sale->getCustomer()?->getId() ?? 0,
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
        $payment->setTenant($user->getTenant());
        $payment->setCustomer($customer);
        $payment->setSale($sale);
        $payment->setAmount($cmd->amount);
        $payment->setCurrency($currency);
        $payment->setUsdRateSnapshot($usdRateSnapshot);
        $payment->setPaymentDate($cmd->date);
        $payment->setMethod($cmd->method);
        $payment->setReference($cmd->reference);
        $payment->setNotes($cmd->notes);

        return $payment;
    }
}
