<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Enquiry;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ContactType extends AbstractType
{
    public const string HONEYPOT = 'website';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'contact.name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('contact', TextType::class, [
                'label' => 'contact.contact',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'contact.message',
                'attr' => ['rows' => 5],
            ])
            // Spam trap: hidden with CSS, bots fill it in, people don't
            ->add(self::HONEYPOT, TextType::class, [
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
            'data_class' => Enquiry::class,
            'translation_domain' => 'messages',
            // Public form without login: CSRF adds nothing here, and leaving it out keeps
            // pages free of session cookies (and cacheable). The honeypot handles spam.
            'csrf_protection' => false,
        ]);
    }
}
