<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Cambio de contraseña propio (/cambiar-password, Fase 6.1).
 *
 * Los tres campos van sin mapear: el controller valida la contraseña
 * actual contra el hash guardado (no hay getter/setter de texto plano
 * en la entidad) y solo entonces persiste el hash nuevo.
 */
final class PasswordChangeFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('currentPassword', PasswordType::class, [
                'label' => 'Contraseña actual',
                'mapped' => false,
                'required' => true,
            ])
            ->add('newPassword', PasswordType::class, [
                'label' => 'Nueva contraseña',
                'mapped' => false,
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Indique la nueva contraseña.'),
                    new Length(
                        min: 8,
                        minMessage: 'La nueva contraseña debe tener al menos {{ limit }} caracteres.'
                    ),
                ],
            ])
            ->add('confirmPassword', PasswordType::class, [
                'label' => 'Repite la nueva contraseña',
                'mapped' => false,
                'required' => true,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
