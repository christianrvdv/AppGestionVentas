<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;

final class UsdRateFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rateDate', DateType::class, [
                'label' => 'Fecha de la tasa',
                'input' => 'datetime_immutable',
                'widget' => 'single_text',
                'required' => true,
                'data' => new \DateTimeImmutable('today'),
                'constraints' => [
                    new NotBlank(message: 'La fecha es obligatoria.'),
                    new LessThanOrEqual(
                        value: 'today',
                        message: 'La fecha no puede ser futura.',
                    ),
                ],
            ])
            ->add('rate', NumberType::class, [
                'label' => 'Tasa USD',
                'required' => true,
                'scale' => 4,
                'html5' => true,
                'attr' => [
                    'step' => '0.0001',
                    'min' => '0.0001',
                    'placeholder' => 'Ej: 350.5000',
                ],
                'constraints' => [
                    new NotBlank(message: 'La tasa es obligatoria.'),
                    new GreaterThan(
                        value: 0,
                        message: 'La tasa debe ser mayor que cero.',
                    ),
                ],
            ])
            ->add('note', TextareaType::class, [
                'label' => 'Nota (opcional)',
                'required' => false,
                'attr' => [
                    'rows' => 2,
                    'placeholder' => 'Fuente, observación, etc.',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'usd_rate_form',
        ]);
    }
}