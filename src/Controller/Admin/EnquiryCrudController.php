<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Enquiry;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class EnquiryCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Enquiry::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Enquiry')
            ->setEntityLabelInPlural('Enquiries')
            ->setDefaultSort(['createdAt' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        // Enquiries come from the website; clients can only read them and mark them as handled
        return $actions
            ->disable(Action::NEW)
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function configureFields(string $pageName): iterable
    {
        if (Crud::PAGE_EDIT === $pageName) {
            yield BooleanField::new('handled', 'Handled');

            return;
        }

        yield from $this->tenantFields();
        yield DateTimeField::new('createdAt', 'Received');
        yield TextField::new('name');
        yield TextField::new('contact', 'Email / phone');
        yield TextareaField::new('message')->setMaxLength(Crud::PAGE_INDEX === $pageName ? 60 : 5000);
        yield TextField::new('locale', 'Language')->hideOnIndex();
        yield BooleanField::new('handled')->renderAsSwitch(false);
    }
}
