<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\InvestmentExpense;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Alta de un gasto de inversión (Fase 3b), embebido en el detalle.
 *
 * Igual que InvestmentItemFormType trabaja con data_class null (array de
 * datos): los atributos de la entidad y el re-prorrateo los aplica
 * InvestmentExpenseService, nunca el form.
 */
final class InvestmentExpenseFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', ChoiceType::class, [
                'label' => 'Categoría',
                'choices' => array_flip(InvestmentExpense::CATEGORY_LABELS),
                'placeholder' => 'Seleccione una categoría',
                'required' => true,
                'constraints' => [
                    new NotBlank(message: 'Seleccione una categoría.'),
                ],
            ])
            ->add('amount', NumberType::class, [
                'label' => 'Monto',
                'required' => true,
                'html5' => true,
                'scale' => 2,
                'input' => 'string',
                'constraints' => [
                    new NotBlank(message: 'El monto es obligatorio.'),
                    new Positive(message: 'El monto debe ser mayor a cero.'),
                ],
            ])
            ->add('expenseDate', DateType::class, [
                'label' => 'Fecha',
                'required' => true,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'constraints' => [
                    new NotBlank(message: 'La fecha es obligatoria.'),
                ],
            ])
            ->add('description', TextType::class, [
                'label' => 'Descripción',
                'required' => false,
                'empty_data' => null,
                'constraints' => [
                    new Length(
                        max: 255,
                        maxMessage: 'La descripción no puede superar los 255 caracteres.',
                    ),
                ],
            ])
            ->add('isAllocated', CheckboxType::class, [
                'label' => 'Prorratear entre ítems',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => null,
        ]);
    }
}
