<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** Super admins only: which hostnames serve which client site. */
class DomainCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Domain::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Domain')
            ->setEntityLabelInPlural('Domains')
            ->setEntityPermission(User::ROLE_SUPER_ADMIN)
            ->setDefaultSort(['hostname' => 'ASC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions->setPermissions([
            Action::INDEX => User::ROLE_SUPER_ADMIN,
            Action::NEW => User::ROLE_SUPER_ADMIN,
            Action::EDIT => User::ROLE_SUPER_ADMIN,
            Action::DELETE => User::ROLE_SUPER_ADMIN,
        ]);
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('hostname')
            ->setHelp('Without https:// or slashes, e.g. jansklusbedrijf.com. Point its DNS (A record) to the server.');
        yield AssociationField::new('tenant', 'Client');
        yield BooleanField::new('isPrimary', 'Primary')
            ->setHelp('Other hostnames of the same client redirect to the primary one.')
            ->renderAsSwitch(false);
    }
}
