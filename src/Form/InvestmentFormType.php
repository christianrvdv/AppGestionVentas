<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;

/**
 * Cabecera de inversión (fecha + descripción). Se usa para crear
 * (InvestmentCreationService resuelve el resto) y para editar el
 * borrador. Los totales, el código y la tasa no son editables.
 */
final class InvestmentFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('investmentDate', DateType::class, [
                'label' => 'Fecha de la inversión',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => true,
                'constraints' => [
                    new LessThanOrEqual(
                        value: 'today',
                        message: 'La fecha no puede ser futura.',
                    ),
                ],
            ])
            ->add('description', TextType::class, [
                'label' => 'Descripción',
                'required' => false,
                'constraints' => [
                    new Length(
                        max: 255,
                        maxMessage: 'La descripción no puede superar los 255 caracteres.',
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
