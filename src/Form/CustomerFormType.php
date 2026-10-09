<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Customer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Alta y edición de un cliente del negocio. El tenant no es campo del
 * form: lo asigna el controller antes de crear el form (ADR-0014).
 *
 * Los campos opcionales vacíos se guardan como null y no como '': las
 * columnas son nullable y los índices únicos por tenant
 * (`uniq_customer_tenant_phone`, `uniq_customer_tenant_email`) no
 * distinguen '' de un valor real.
 */
final class CustomerFormType extends AbstractType
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
            ->add('phone', TextType::class, [
                'label' => 'Teléfono',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 30,
                        maxMessage: 'El teléfono no puede superar los 30 caracteres.',
                    ),
                ],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Email(message: 'Introduce una dirección de email válida.'),
                    new Length(
                        max: 180,
                        maxMessage: 'El email no puede superar los 180 caracteres.',
                    ),
                ],
            ])
            ->add('address', TextType::class, [
                'label' => 'Dirección',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 255,
                        maxMessage: 'La dirección no puede superar los 255 caracteres.',
                    ),
                ],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'Notas',
                'required' => false,
                'empty_data' => null,
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Cliente activo',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Customer::class,
        ]);
    }
}
