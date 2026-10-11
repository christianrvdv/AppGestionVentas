<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Customer;
use App\Entity\Investment;
use App\Entity\InvestmentItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Alta de una venta (Fase 3c.2). El form solo recoge DATOS: la validación
 * de negocio (inversión confirmada, stock, ítem perteneciente a la
 * inversión, ganancia reconocida) vive en SaleService::register.
 *
 * Las opciones (inversiones, clientes, ítems vendibles y su stock) las
 * resuelve el controller, que es quien conoce el tenant activo; el form
 * no consulta la BD por su cuenta.
 *
 * Reglas que la UI hace visibles:
 *   - Solo se venden ítems con stock > 0 de inversiones confirmadas.
 *   - El precio unitario es libre (regateo): el sugerido se muestra como
 *     referencia en el atributo data-suggested-price de cada opción.
 *   - Una venta pertenece a UNA sola inversión (Sale tiene un único
 *     investment_id), así que el selector de ítem se acota a ella.
 */
final class SaleFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
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
                    new NotBlank(message: 'Seleccione la inversión de la venta.'),
                ],
            ])
            ->add('customer', EntityType::class, [
                'label' => 'Cliente',
                'class' => Customer::class,
                'choices' => $options['customers'],
                'choice_label' => 'name',
                'placeholder' => 'Consumidor final (sin cliente)',
                'required' => false,
            ])
            ->add('saleDate', DateType::class, [
                'label' => 'Fecha',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La fecha de la venta es obligatoria.'),
                    new LessThanOrEqual(
                        value: 'today',
                        message: 'La fecha de la venta no puede ser futura.',
                    ),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'empty_data' => null,
            ])
            ->add('lines', CollectionType::class, [
                'entry_type' => SaleLineFormType::class,
                'entry_options' => [
                    'label' => false,
                    'sellable_items' => $options['sellable_items'],
                    'stock_by_item' => $options['stock_by_item'],
                ],
                'allow_add' => true,
                'allow_delete' => true,
                'by_reference' => false,
                'prototype' => true,
                'prototype_name' => '__line__',
                'label' => false,
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
            /** @var InvestmentItem[] $sellable_items */
            'sellable_items' => [],
            /** @var array<int, int> $stock_by_item */
            'stock_by_item' => [],
        ]);

        $resolver->setAllowedTypes('investments', 'iterable');
        $resolver->setAllowedTypes('customers', 'iterable');
        $resolver->setAllowedTypes('sellable_items', 'iterable');
        $resolver->setAllowedTypes('stock_by_item', 'array');
    }
}
