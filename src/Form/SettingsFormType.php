<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Investment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\LessThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

final class SettingsFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('tenant_name', TextType::class, [
                'label' => 'Nombre del tenant',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'El nombre del tenant es obligatorio.'),
                    new Length(max: 150, maxMessage: 'El nombre no puede superar 150 caracteres.'),
                ],
                'attr' => [
                    'placeholder' => 'Mi negocio',
                ],
            ])
            ->add('tenant_slug', TextType::class, [
                'label' => 'Slug (solo lectura)',
                'required' => false,
                'disabled' => true,
                'attr' => [
                    'placeholder' => 'mi-negocio',
                ],
                'help' => 'El slug es inmutable desde la UI. Para cambiarlo, contacte al administrador.',
            ])
            ->add('base_currency', TextType::class, [
                'label' => 'Moneda base',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'La moneda base es obligatoria.'),
                    new Length(exactly: 3, exactMessage: 'La moneda debe tener exactamente 3 letras.'),
                    new Regex(
                        pattern: '/^[A-Z]{3}$/',
                        message: 'La moneda debe ser 3 letras mayúsculas (ej. ARS, USD, EUR).',
                    ),
                ],
                'attr' => [
                    'placeholder' => 'ARS',
                    'maxlength' => 3,
                    'style' => 'text-transform: uppercase;',
                ],
                'help' => 'Código ISO 4217 de 3 letras. Cambiar esto con inversiones existentes puede afectar reportes.',
            ])
            ->add('currency_scale', NumberType::class, [
                'label' => 'Decimales de la moneda',
                'required' => true,
                'html5' => true,
                'attr' => [
                    'min' => 0,
                    'max' => 4,
                    'step' => 1,
                ],
                'constraints' => [
                    new NotBlank(message: 'Los decimales son obligatorios.'),
                    new GreaterThanOrEqual(value: 0, message: 'Los decimales no pueden ser negativos.'),
                    new LessThanOrEqual(value: 4, message: 'Máximo 4 decimales permitidos.'),
                ],
                'help' => 'Número de decimales para la moneda base (0-4). Ej: 0 para JPY, 2 para ARS/USD, 3 para BHD.',
            ])
            ->add('default_margin_pct', NumberType::class, [
                'label' => 'Margen por defecto (%)',
                'required' => true,
                'scale' => 2,
                'html5' => true,
                'attr' => [
                    'min' => 0,
                    'max' => 100,
                    'step' => '0.01',
                    'placeholder' => '40.00',
                ],
                'constraints' => [
                    new NotBlank(message: 'El margen es obligatorio.'),
                    new GreaterThanOrEqual(value: 0, message: 'El margen no puede ser negativo.'),
                    new LessThanOrEqual(value: 100, message: 'El margen no puede superar 100%.'),
                ],
                'help' => 'Porcentaje de margen aplicado al costo para sugerir precio de venta (0-100, 2 decimales).',
            ])
            ->add('recovery_mode', ChoiceType::class, [
                'label' => 'Modo de recuperación por defecto',
                'required' => true,
                'choices' => [
                    'Por producto (PER_PRODUCT)' => Investment::RECOVERY_PER_PRODUCT,
                    'Inversión primero (INVESTMENT_FIRST)' => Investment::RECOVERY_INVESTMENT_FIRST,
                ],
                'help' => 'Cómo se asignan los pagos: por producto individualmente o priorizando recuperar la inversión completa.',
            ])
            ->add('usd_control_enabled', CheckboxType::class, [
                'label' => 'Control de tasa USD habilitado',
                'required' => false,
                'help' => 'Si está activo, se requiere tasa USD para confirmar inversiones y se habilita la revaluación.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
            'csrf_protection' => true,
            'csrf_field_name' => '_token',
            'csrf_token_id' => 'settings_form',
        ]);
    }
}