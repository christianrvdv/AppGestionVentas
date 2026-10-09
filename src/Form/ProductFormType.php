<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Product;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Alta y edición de un producto del negocio. El tenant no es campo del
 * form: lo asigna el controller antes de crear el form (ADR-0014).
 *
 * Los campos opcionales vacíos se guardan como null y no como '': las
 * columnas son nullable y los índices únicos por tenant
 * (`uniq_product_tenant_sku`) no distinguen '' de un valor real.
 */
final class ProductFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'El nombre es obligatorio.'),
                    new Length(
                        max: 150,
                        maxMessage: 'El nombre no puede superar los 150 caracteres.',
                    ),
                ],
            ])
            ->add('sku', TextType::class, [
                'label' => 'SKU',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 50,
                        maxMessage: 'El SKU no puede superar los 50 caracteres.',
                    ),
                ],
            ])
            ->add('category', TextType::class, [
                'label' => 'Categoría',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 80,
                        maxMessage: 'La categoría no puede superar los 80 caracteres.',
                    ),
                ],
            ])
            ->add('defaultMarginPct', NumberType::class, [
                'label' => 'Margen por defecto (%)',
                'required' => false,
                'html5' => true,
                'scale' => 2,
                'input' => 'string',
                'constraints' => [
                    new Range(
                        min: 0,
                        max: 100,
                        notInRangeMessage: 'El margen debe estar entre {{ min }} y {{ max }}%.',
                    ),
                ],
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Producto activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
