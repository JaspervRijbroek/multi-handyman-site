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

/** Public sign-up form on PLATFORM_HOST/{es,en}/register. */
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
                'label' => 'signup.business_name',
                'attr' => ['autocomplete' => 'organization'],
            ])
            ->add('contactName', TextType::class, [
                'label' => 'signup.contact_name',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'signup.email',
                'help' => 'signup.email_help',
                'attr' => ['autocomplete' => 'email'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'signup.phone',
                'attr' => ['autocomplete' => 'tel', 'placeholder' => '+34 600 123 456'],
            ])
            ->add('whatsapp', TelType::class, [
                'label' => 'signup.whatsapp',
                'required' => false,
                'help' => 'signup.whatsapp_help',
            ])
            ->add('streetAddress', TextType::class, [
                'label' => 'signup.street',
                'attr' => ['autocomplete' => 'street-address'],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'signup.postal_code',
                'attr' => ['autocomplete' => 'postal-code'],
            ])
            ->add('city', TextType::class, [
                'label' => 'signup.town',
                'attr' => ['autocomplete' => 'address-level2'],
            ])
            ->add('country', CountryType::class, [
                'label' => 'signup.country',
                'preferred_choices' => ['ES'],
                'attr' => ['autocomplete' => 'country'],
            ])
            ->add('serviceArea', TextType::class, [
                'label' => 'signup.service_area',
                'required' => false,
                'attr' => ['placeholder' => 'signup.service_area_placeholder'],
            ])
            ->add('domain', TextType::class, [
                'label' => 'signup.domain',
                'required' => false,
                'help' => 'signup.domain_help',
            ])
            ->add('locales', ChoiceType::class, [
                'label' => 'signup.locales',
                'choices' => self::LANGUAGES,
                // Each language in its own name
                'choice_translation_domain' => false,
                'multiple' => true,
                'expanded' => true,
            ])
            ->add('defaultLocale', ChoiceType::class, [
                'label' => 'signup.default_locale',
                'choices' => self::LANGUAGES,
                'choice_translation_domain' => false,
            ])
            ->add('taxId', TextType::class, [
                'label' => 'signup.tax_id',
                'required' => false,
                'help' => 'signup.tax_id_help',
            ])
            ->add('message', TextareaType::class, [
                'label' => 'signup.message',
                'required' => false,
                'attr' => ['rows' => 4],
            ])
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'mapped' => false,
                'first_options' => ['label' => 'signup.password', 'attr' => ['autocomplete' => 'new-password']],
                'second_options' => ['label' => 'signup.password_repeat', 'attr' => ['autocomplete' => 'new-password']],
                'invalid_message' => 'The passwords do not match.',
                'constraints' => [new Assert\NotBlank(), new Assert\Length(min: 10, max: 4096, minMessage: 'Use at least {{ limit }} characters.')],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => 'signup.consent',
                'mapped' => false,
                'row_attr' => ['class' => 'p-check'],
                'constraints' => [new Assert\IsTrue(message: 'Please agree, otherwise we cannot set up your website.')],
            ])
            // Honeypot: hidden for people, bots fill it in
            ->add(self::HONEYPOT, TextType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'signup.honeypot',
                'row_attr' => ['class' => 'hp', 'aria-hidden' => 'true'],
                'attr' => ['tabindex' => '-1', 'autocomplete' => 'off'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Signup::class,
            // Labels and help texts live in translations/platform.*.yaml
            'translation_domain' => 'platform',
            'empty_data' => static fn () => (new Signup())->setLocales(['en', 'es']),
        ]);
    }
}
