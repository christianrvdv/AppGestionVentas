<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use App\Service\TenantContext;
use Doctrine\ORM\EntityRepository;
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
 * Agregar un ítem a una inversión en borrador. Delega en
 * InvestmentItemService::addItem() (valida tenant, estado y rangos,
 * y dispara prorrateo + recálculo de costos).
 */
final class InvestmentItemFormType extends AbstractType
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tenantId = $this->tenantContext->getTenantId();

        $builder
            ->add('product', EntityType::class, [
                'label' => 'Producto',
                'class' => Product::class,
                'choice_label' => static fn (Product $product): string => $product->getSku()
                    ? sprintf('%s (%s)', $product->getName(), $product->getSku())
                    : $product->getName(),
                'placeholder' => 'Seleccione un producto',
                'required' => true,
                'query_builder' => static function (EntityRepository $repository) use ($tenantId) {
                    return $repository->createQueryBuilder('p')
                        ->andWhere('p.tenant = :tenantId')
                        ->andWhere('p.isActive = :active')
                        ->setParameter('tenantId', $tenantId)
                        ->setParameter('active', true)
                        ->orderBy('p.name', 'ASC');
                },
                'constraints' => [
                    new NotBlank(message: 'Seleccione un producto.'),
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
            ->add('unitCost', NumberType::class, [
                'label' => 'Costo unitario',
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'constraints' => [
                    new NotBlank(message: 'El costo unitario es obligatorio.'),
                    new GreaterThanOrEqual(
                        value: 0,
                        message: 'El costo unitario no puede ser negativo.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
