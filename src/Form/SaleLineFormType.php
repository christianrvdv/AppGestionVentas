<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InvestmentItem;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Una línea de la venta: ítem + cantidad + precio unitario.
 *
 * El ítem se elige entre los VENDIBLES del tenant (ítems con stock > 0),
 * agrupados por inversión. El acote a la inversión elegida en la cabecera
 * lo hace el cliente (Stimulus); el servidor valida igualmente que el
 * ítem pertenezca a la inversión (ItemNotInInvestmentException), porque
 * una venta tiene una sola inversión.
 *
 * Los atributos data-* de cada opción alimentan la estimación en vivo
 * (Stimulus): costo real unitario, precio sugerido y stock disponible.
 * Son una ayuda de cálculo, nunca la fuente de verdad.
 */
final class SaleLineFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('investmentItem', EntityType::class, [
                'label' => 'Ítem',
                'class' => InvestmentItem::class,
                'choices' => $options['sellable_items'],
                'choice_label' => static fn (InvestmentItem $item): string => $item->getProduct()->getSku() !== null
                    ? sprintf('%s (%s)', $item->getProduct()->getName(), $item->getProduct()->getSku())
                    : $item->getProduct()->getName(),
                'group_by' => static fn (InvestmentItem $item): string => $item->getInvestment()->getCode(),
                'choice_attr' => function (InvestmentItem $item) use ($options): array {
                    return [
                        'data-investment-id' => $item->getInvestment()->getId(),
                        'data-unit-cost' => $item->getRealUnitCost(),
                        'data-suggested-price' => $item->getEffectivePrice(),
                        'data-stock' => $options['stock_by_item'][$item->getId()] ?? 0,
                    ];
                },
                'placeholder' => 'Seleccione un ítem',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Seleccione el ítem a vender.'),
                ],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'Cantidad',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La cantidad es obligatoria.'),
                    new Positive(message: 'La cantidad debe ser mayor a cero.'),
                    new GreaterThanOrEqual(
                        value: 1,
                        message: 'La cantidad debe ser al menos 1.',
                    ),
                ],
            ])
            ->add('unitPrice', NumberType::class, [
                'label' => 'Precio unitario',
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'El precio unitario es obligatorio.'),
                    new GreaterThanOrEqual(
                        value: 0,
                        message: 'El precio unitario no puede ser negativo.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            /** @var InvestmentItem[] $sellable_items */
            'sellable_items' => [],
            /** @var array<int, int> $stock_by_item */
            'stock_by_item' => [],
        ]);

        $resolver->setRequired('sellable_items');
        $resolver->setAllowedTypes('sellable_items', 'iterable');
        $resolver->setAllowedTypes('stock_by_item', 'array');
    }
}
