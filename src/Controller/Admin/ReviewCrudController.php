<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Review;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/**
 * Reviews come from the website. Clients can't write or change them (so they
 * stay genuine), only decide which ones are shown, or delete spam.
 */
class ReviewCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Review::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Review')
            ->setEntityLabelInPlural('Reviews')
            ->setDefaultSort(['published' => 'ASC', 'createdAt' => 'DESC'])
            ->setHelp(Crud::PAGE_INDEX, 'Customers write reviews on your website, or through the review link you send them from a finished job. They are only shown once you switch on "Show on website".');
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_EDIT === $pageName) {
            yield BooleanField::new('published', 'Show on website');

            return;
        }

        yield from $this->tenantFields();
        yield DateTimeField::new('createdAt', 'Received');
        yield IntegerField::new('rating', 'Stars');
        yield TextField::new('authorName', 'Name')->formatValue(static fn (?string $name) => $name ?: 'Anonymous');
        yield AssociationField::new('project', 'Job')->hideOnIndex();
        yield TextField::new('town')->hideOnIndex();
        yield TextareaField::new('text', 'Review')->setMaxLength(Crud::PAGE_INDEX === $pageName ? 80 : 2000);
        yield TextField::new('locale', 'Language')->hideOnIndex();
        yield BooleanField::new('published', 'Show on website');
    }
}
