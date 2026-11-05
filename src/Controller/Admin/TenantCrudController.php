<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\TenantAccessVoter;
use App\Tenant\TenantContext;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Super admins: manage all clients. Clients: their own "Settings" page
 * (business details, logo, work area, texts and legal details).
 */
class TenantCrudController extends AbstractCrudController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Tenant::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Client')
            ->setEntityLabelInPlural('Clients')
            ->setEntityPermission(TenantAccessVoter::ACCESS)
            ->setPageTitle(Crud::PAGE_EDIT, fn (Tenant $tenant) => $this->tenantContext->has() ? 'Settings' : $tenant->getName());
    }

    public function configureActions(Actions $actions): Actions
    {
        $actions
            ->setPermission(Action::NEW, User::ROLE_SUPER_ADMIN)
            ->setPermission(Action::DELETE, User::ROLE_SUPER_ADMIN);

        // Clients only ever see their own settings: save and stay on the page
        if ($this->tenantContext->has()) {
            $actions
                ->remove(Crud::PAGE_EDIT, Action::SAVE_AND_RETURN)
                ->update(Crud::PAGE_EDIT, Action::SAVE_AND_CONTINUE, fn (Action $action) => $action->setLabel('Save settings'));
        }

        return $actions;
    }

    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);

        // Tenant is not tenant-owned itself, so restrict the list explicitly for clients
        if ($tenant = $this->tenantContext->get()) {
            $qb->andWhere('entity.id = :ownTenant')->setParameter('ownTenant', $tenant->getId());
        }

        return $qb;
    }

    public function configureFields(string $pageName): iterable
    {
        $isSuperAdmin = $this->isGranted(User::ROLE_SUPER_ADMIN);
        $locales = $this->tenantContext->get()?->getLocales() ?? Tenant::SUPPORTED_LOCALES;
        $localeChoices = array_combine(array_map('strtoupper', Tenant::SUPPORTED_LOCALES), Tenant::SUPPORTED_LOCALES);

        yield FormField::addTab('Business', 'fa fa-store');
        yield TextField::new('name', 'Business name');
        yield ImageField::new('logoName', 'Logo')
            ->setBasePath('uploads/logos')
            ->setUploadDir('public/uploads/logos')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setFileConstraints(new Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp', 'image/svg+xml']))
            ->setHelp('Shown at the top of your website and in Google. PNG, JPG, WebP or SVG, max 2 MB. A transparent background works best.');
        if ($isSuperAdmin) {
            yield SlugField::new('slug')->setTargetFieldName('name')->hideOnIndex();
            yield BooleanField::new('active')->renderAsSwitch(false);
            yield ChoiceField::new('locales', 'Languages')
                ->setChoices($localeChoices)
                ->allowMultipleChoices()
                ->renderExpanded();
            yield ChoiceField::new('defaultLocale', 'Main language')
                ->setChoices($localeChoices)
                ->hideOnIndex();
            yield AssociationField::new('domains')->onlyOnIndex();
        }
        yield TextField::new('phone')->hideOnIndex();
        yield TextField::new('whatsapp', 'WhatsApp number')
            ->setHelp('International format, e.g. +34 600 123 456')
            ->hideOnIndex();
        yield EmailField::new('email', 'Send enquiries to')->hideOnIndex();

        yield FormField::addTab('Work area', 'fa fa-map-location-dot')
            ->setHelp('Where you take on jobs. Shown on your homepage, with your contact details and in the footer, and passed on to Google and AI assistants.');
        yield TextField::new('serviceArea', 'Towns and areas you work in')
            ->setHelp('Separate places with commas, e.g. Jávea, Dénia, Moraira and surroundings')
            ->hideOnIndex();

        yield FormField::addTab('Website texts', 'fa fa-pen');
        yield TranslatedField::new('tagline', 'Tagline')->setLocales($locales)->hideOnIndex();
        yield TranslatedField::new('intro', 'Introduction')->setLocales($locales)->multiline()->hideOnIndex();
        yield TranslatedField::new('about', 'About you')->setLocales($locales)->multiline()->hideOnIndex();

        yield FormField::addTab('Legal', 'fa fa-scale-balanced');
        yield TextField::new('legalName', 'Legal name (owner or company)')
            ->setHelp('Shown in the legal notice (aviso legal), required by Spanish law.')
            ->hideOnIndex();
        yield TextField::new('taxId', 'NIF / NIE / CIF')->hideOnIndex();
        yield TextField::new('legalAddress', 'Registered address')->hideOnIndex();

        if ($isSuperAdmin) {
            yield FormField::addTab('Look & SEO', 'fa fa-palette');
            yield ColorField::new('primaryColor', 'Brand colour')->hideOnIndex();
            yield TextField::new('googleSiteVerification', 'Google Search Console code')
                ->setHelp('Only the content value of the google-site-verification meta tag.')
                ->hideOnIndex();
            yield TextField::new('plausibleDomain', 'Plausible analytics domain')
                ->setHelp('Cookieless analytics, no consent banner needed.')
                ->hideOnIndex();
        }
    }
}
