<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Service;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;

class ServiceCrudController extends AbstractTenantCrudController
{
    public static function getEntityFqcn(): string
    {
        return Service::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Service')
            ->setEntityLabelInPlural('Services')
            ->setDefaultSort(['position' => 'ASC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Your services');
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->tenantFields();
        yield TranslatedField::new('title', 'Title')->setLocales($this->locales());
        yield TranslatedField::new('description', 'Description')
            ->setLocales($this->locales())
            ->multiline()
            ->hideOnIndex();
        yield IntegerField::new('position', 'Order')
            ->setHelp('Lower numbers are shown first.');
    }
}
