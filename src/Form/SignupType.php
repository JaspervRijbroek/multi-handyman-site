<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Signup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/** Public sign-up form on PLATFORM_HOST/register. */
final class SignupType extends AbstractType
{
    public const string HONEYPOT = 'website';

    private const array LANGUAGES = [
        'English' => 'en',
        'Español' => 'es',
        'Nederlands' => 'nl',
        'Deutsch' => 'de',
        'Français' => 'fr',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('businessName', TextType::class, [
                'label' => 'Business name',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('contactName', TextType::class, [
                'label' => 'Your name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'help' => 'This becomes your login, and website enquiries are sent here.',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Phone',
                'attr' => ['autocomplete' => 'tel', 'placeholder' => '+34 600 123 456'],
            ])
            ->add('whatsapp', TelType::class, [
                'label' => 'WhatsApp number',
                'required' => false,
                'help' => 'Leave empty if it is the same as your phone.',
            ])
            ->add('streetAddress', TextType::class, [
                'label' => 'Street and number',
                'attr' => ['autocomplete' => 'street-address'],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Postal code',
                'attr' => ['autocomplete' => 'postal-code'],
            ])
            ->add('city', TextType::class, [
                'label' => 'Town',
                'attr' => ['autocomplete' => 'address-level2'],
            ])
            ->add('country', CountryType::class, [
                'label' => 'Country',
                'preferred_choices' => ['ES'],
                'attr' => ['autocomplete' => 'country'],
            ])
            ->add('serviceArea', TextType::class, [
                'label' => 'Towns and areas you work in',
                'required' => false,
                'attr' => ['placeholder' => 'e.g. Jávea, Dénia, Moraira and surroundings'],
            ])
            ->add('domain', TextType::class, [
                'label' => 'Your domain name',
                'required' => false,
                'help' => 'Only if you already have one, e.g. myhandyman.es. No domain yet? We help you choose one.',
            ])
            ->add('locales', ChoiceType::class, [
                'label' => 'Languages for your website',
                'choices' => self::LANGUAGES,
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('defaultLocale', ChoiceType::class, [
                'label' => 'Main language',
                'choices' => self::LANGUAGES,
            ])
            ->add('taxId', TextType::class, [
                'label' => 'NIF / NIE / CIF',
                'required' => false,
                'help' => 'For the legal notice on your website. You can add it later.',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'What do you do? Anything else we should know?',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'Choose a password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096, minMessage: 'Use at least {{ limit }} characters.')],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'I agree that these details are stored to set up my website and to contact me about it.',
                'mapped' => false,
                'row_attr' => ['class' => 'p-check'],
                'constraints' => [new Assert\IsTrue(message: 'Please agree, otherwise we cannot set up your website.')],
            ])
            // Honeypot: hidden for people, bots fill it in
            ->add(self::HONEYPOT, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'Leave this field empty',
                'row_attr' => ['class' => 'hp', 'aria-hidden' => 'true'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Signup::class,
            'empty_data' => static fn () => (new Signup())->setLocales(['en', 'es']),
        ]);
    }
}
