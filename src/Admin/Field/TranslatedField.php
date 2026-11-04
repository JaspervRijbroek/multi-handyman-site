<?php

declare(strict_types=1);

namespace App\Admin\Field;

use App\Form\TranslatedTextType;
use EasyCorp\Bundle\EasyAdminBundle\Contracts\Field\FieldInterface;
use EasyCorp\Bundle\EasyAdminBundle\Field\FieldTrait;

/**
 * EasyAdmin field for translatable JSON texts: one input per enabled language.
 */
final class TranslatedField implements FieldInterface
{
    use FieldTrait;

    public static function new(string $propertyName, $label = null): self
    {
        return (new self())
            ->setProperty($propertyName)
            ->setLabel($label)
            ->setTemplatePath('admin/field/translated.html.twig')
            ->setFormType(TranslatedTextType::class)
            ->addCssClass('field-translated');
    }

    /** @param list<string> $locales */
    public function setLocales(array $locales): self
    {
        $this->setFormTypeOption('locales', $locales);

        return $this;
    }

    public function multiline(bool $multiline = true): self
    {
        $this->setFormTypeOption('multiline', $multiline);

        return $this;
    }
}
