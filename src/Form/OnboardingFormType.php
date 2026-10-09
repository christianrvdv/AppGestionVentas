<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Tenant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Alta de un negocio propio (onboarding, ADR-0015).
 *
 * Se bindea contra una entidad Tenant nueva: el controller setea la
 * relación con el usuario creando la TenantMembership, y activa el
 * negocio en la sesión recién después del flush (cuando existe el id).
 *
 * El slug es OPCIONAL: si se deja vacío, el controller lo deriva del
 * nombre. Se expone igual para que quien ya tenga un slug pensado (o
 * quiera corregir el autogenerado) pueda escribirlo.
 */
final class OnboardingFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nombre del negocio',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'El nombre del negocio es obligatorio.'),
                    new Length(
                        max: 150,
                        maxMessage: 'El nombre no puede superar los 150 caracteres.',
                    ),
                ],
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug (URL)',
                'required' => false,
                'help' => 'Opcional. Si lo dejás vacío se genera a partir del nombre.',
                'constraints' => [
                    new Length(
                        max: 80,
                        maxMessage: 'El slug no puede superar los 80 caracteres.',
                    ),
                    new Regex(
                        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                        message: 'El slug solo puede contener minúsculas, números y guiones.',
                    ),
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Tenant::class,
        ]);
    }
}
