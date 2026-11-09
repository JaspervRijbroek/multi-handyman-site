<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Review;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('rating', ChoiceType::class, [
                'label' => 'reviews.rating',
                'choices' => ['★★★★★' => 5, '★★★★' => 4, '★★★' => 3, '★★' => 2, '★' => 1],
                'choice_translation_domain' => false,
                // Screen readers say "4/5" instead of "black star" four times
                'choice_attr' => static fn (int $rating) => ['aria-label' => $rating.'/5'],
                'expanded' => true,
            ])
            ->add('authorName', TextType::class, [
                'label' => 'reviews.name',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('town', TextType::class, [
                'label' => 'reviews.town',
                'required' => false,
            ])
            ->add('text', TextareaType::class, [
                'label' => 'reviews.text',
                'attr' => ['rows' => 5],
            ])
            // Spam trap, see ContactType
            ->add(ContactType::HONEYPOT, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Website',
                'row_attr' => ['class' => 'hp', 'aria-hidden' => 'true'],
                'attr' => ['autocomplete' => 'off', 'tabindex' => '-1'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Review::class,
            'translation_domain' => 'messages',
            // Public form without login, like the contact form
            'csrf_protection' => false,
        ]);
    }
}
