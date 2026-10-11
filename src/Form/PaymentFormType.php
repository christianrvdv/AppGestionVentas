<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\Payment;
use App\Entity\Sale;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Registro de un pago (Fase 3d). El form solo recoge DATOS: la validación
 * de negocio (inversión existente, cliente del mismo tenant, cliente
 * coincidente con el de la venta, moneda) vive en PaymentService.
 *
 * Dos modos, elegidos por la opción "sale":
 *
 *   - sale = una venta: el pago se aplica a ella. NO se piden inversión ni
 *     cliente: los toma la venta (la moneda base de la inversión, el
 *     cliente de la venta). Es el flujo de "Registrar pago" del detalle.
 *
 *   - sale = null: pago a cuenta / anticipo. Se piden inversión (solo
 *     define la moneda) y cliente, que es quien tendrá el saldo a favor.
 *     Un anticipo sin cliente no tiene a quién acreditarlo, así que el
 *     campo es obligatorio en este modo aunque el dominio acepte null.
 *
 * El form no consulta la BD por su cuenta: las opciones las resuelve el
 * controller, que es quien conoce el tenant activo.
 */
final class PaymentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Sale|null $sale */
        $sale = $options['sale'];

        if (null === $sale) {
            $builder
                ->add('investment', EntityType::class, [
                    'label' => 'Inversión',
                    'class' => Investment::class,
                    'choices' => $options['investments'],
                    'choice_label' => static fn (Investment $investment): string => $investment->getDescription() !== null
                        ? sprintf('%s · %s', $investment->getCode(), $investment->getDescription())
                        : $investment->getCode(),
                    'placeholder' => 'Seleccione una inversión',
                    'required' => true,
                    'constraints' => [
                        new NotBlank(message: 'Seleccione la inversión que define la moneda del pago.'),
                    ],
                ])
                ->add('customer', EntityType::class, [
                    'label' => 'Cliente',
                    'class' => Customer::class,
                    'choices' => $options['customers'],
                    'choice_label' => 'name',
                    'placeholder' => 'Seleccione un cliente',
                    'required' => true,
                    'constraints' => [
                        new NotBlank(message: 'Un pago a cuenta necesita un cliente a quien acreditarlo.'),
                    ],
                ]);
        }

        $builder
            ->add('amount', NumberType::class, [
                'label' => 'Monto',
                'scale' => 2,
                'html5' => true,
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'El monto del pago es obligatorio.'),
                    new GreaterThan(
                        value: 0,
                        message: 'El monto del pago debe ser mayor que cero.',
                    ),
                ],
            ])
            ->add('date', DateType::class, [
                'label' => 'Fecha',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La fecha del pago es obligatoria.'),
                ],
            ])
            ->add('method', ChoiceType::class, [
                'label' => 'Método',
                'choices' => [
                    'Efectivo' => Payment::METHOD_CASH,
                    'Transferencia' => Payment::METHOD_TRANSFER,
                    'Tarjeta' => Payment::METHOD_CARD,
                    'Otro' => Payment::METHOD_OTHER,
                ],
                'placeholder' => false,
                'required' => true,
            ])
            ->add('reference', TextType::class, [
                'label' => 'Referencia',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 100,
                        maxMessage: 'La referencia no puede superar los 100 caracteres.',
                    ),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'empty_data' => null,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            /** @var Investment[] $investments */
            'investments' => [],
            /** @var Customer[] $customers */
            'customers' => [],
            'sale' => null,
        ]);

        $resolver->setAllowedTypes('investments', 'iterable');
        $resolver->setAllowedTypes('customers', 'iterable');
        $resolver->setAllowedTypes('sale', [Sale::class, 'null']);
    }

    /**
     * Nombre de bloque explícito: los tests funcionales envían el form a
     * mano y el prefijo debe ser estable, no derivado del nombre de la
     * clase.
     */
    public function getBlockPrefix(): string
    {
        return 'payment';
    }
}
