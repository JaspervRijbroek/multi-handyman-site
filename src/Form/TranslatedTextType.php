<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Edits a JSON text map (['en' => '...', 'es' => '...']) with one input per language.
 */
final class TranslatedTextType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['locales'] as $locale) {
            $builder->add($locale, $options['multiline'] ? TextareaType::class : TextType::class, [
                'label' => strtoupper($locale),
                'required' => false,
                'attr' => $options['multiline'] ? ['rows' => 4] : [],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'locales' => ['en'],
            'multiline' => false,
            'empty_data' => [],
        ]);
        $resolver->setAllowedTypes('locales', 'string[]');
        $resolver->setAllowedTypes('multiline', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'translated_text';
    }
}
