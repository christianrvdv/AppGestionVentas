<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Cancelación de inversión: el motivo es obligatorio para el dominio
 * (InvestmentStateMachine::cancel exige reason).
 */
final class InvestmentCancelFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextType::class, [
            'label' => 'Motivo de cancelación',
            'required' => true,
                'constraints' => [
                    new NotBlank(message: 'El motivo es obligatorio.'),
                    new Length(
                        max: 255,
                        maxMessage: 'El motivo no puede superar los 255 caracteres.',
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
