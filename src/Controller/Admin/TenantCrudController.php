<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Billing\BillingService;
use App\Entity\SearchConsoleStatus;
use App\Entity\Tenant;
use App\Entity\Trade;
use App\Entity\User;
use App\Onboarding\ClientOnboarding;
use App\Security\TenantAccessVoter;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ColorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CountryField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\SlugField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\UrlField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints\Image;

/**
 * Super admins: manage all clients. Clients: their own "Settings" page
 * (business details, logo, address, work area, texts and legal details).
 *
 * New clients are not created here but through onboarding (Sign-ups → Add
 * client / Approve), which also creates the domain, login and Google setup.
 */
class TenantCrudController extends AbstractCrudController
{
    use OnboardingFlashesTrait;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ClientOnboarding $onboarding,
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly BillingService $billing,
        private readonly EntityManagerInterface $entityManager,
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
        $sendSetup = Action::new('sendPaymentSetup', 'Send payment setup email', 'fa fa-credit-card')
            ->linkToCrudAction('sendPaymentSetup')
            ->displayIf(static fn (Tenant $tenant) => !$tenant->isBillingExempt())
            ->askConfirmation('Email %entity_name% a link to set up automatic payment?', 'Send');

        // No token by default, so no MCP access until the client asks for it
        $generateMcpToken = Action::new('generateMcpToken', 'Generate MCP token', 'fa fa-key')
            ->linkToCrudAction('createMcpToken')
            ->renderAsForm()
            ->displayIf(static fn (Tenant $tenant) => !$tenant->hasMcpToken());
        $resetMcpToken = Action::new('resetMcpToken', 'Reset MCP token', 'fa fa-rotate')
            ->linkToCrudAction('createMcpToken')
            ->renderAsForm()
            ->askConfirmation('Create a new MCP token? The current token stops working right away.', 'Reset token')
            ->displayIf(static fn (Tenant $tenant) => $tenant->hasMcpToken());
        $removeMcpToken = Action::new('removeMcpToken', 'Remove MCP token', 'fa fa-trash')
            ->linkToCrudAction('removeMcpToken')
            ->renderAsForm()
            ->askConfirmation('Remove the MCP token? AI assistants lose access right away.', 'Remove token')
            ->displayIf(static fn (Tenant $tenant) => $tenant->hasMcpToken());

        $actions
            ->setPermission(Action::NEW, User::ROLE_SUPER_ADMIN)
            ->setPermission(Action::DELETE, User::ROLE_SUPER_ADMIN)
            ->setPermission('connectGoogle', User::ROLE_SUPER_ADMIN)
            ->setPermission('addClient', User::ROLE_SUPER_ADMIN)
            ->setPermission('sendPaymentSetup', User::ROLE_SUPER_ADMIN)
            ->add(Crud::PAGE_INDEX, $sendSetup)
            ->add(Crud::PAGE_EDIT, $sendSetup)
            ->add(Crud::PAGE_DETAIL, $sendSetup)
            ->add(Crud::PAGE_EDIT, $generateMcpToken)
            ->add(Crud::PAGE_EDIT, $resetMcpToken)
            ->add(Crud::PAGE_EDIT, $removeMcpToken)
            // Clients are created through onboarding, which sets up everything at once
            ->disable(Action::NEW);

        if ($this->isGranted(User::ROLE_SUPER_ADMIN)) {
            $connectGoogle = Action::new('connectGoogle', 'Connect Google', 'fab fa-google')
                ->linkToCrudAction('connectGoogle')
                ->renderAsForm();

            $actions
                ->add(Crud::PAGE_INDEX, Action::new('addClient', 'Add client', 'fa fa-plus')
                    ->createAsGlobalAction()
                    ->linkToUrl(fn () => $this->adminUrlGenerator->setController(SignupCrudController::class)->setAction(Action::NEW)->unset('entityId')->generateUrl())
                    ->asPrimaryAction())
                ->add(Crud::PAGE_INDEX, Action::DETAIL)
                ->add(Crud::PAGE_INDEX, $connectGoogle)
                ->add(Crud::PAGE_DETAIL, $connectGoogle);
        }

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
            ->setFileConstraints(new Image(maxSize: '2M', mimeTypes: ['image/png', 'image/jpeg', 'image/webp']))
            ->setHelp('Shown at the top of your website and in Google. PNG, JPG or WebP, max 2 MB. A transparent background works best.');
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
        yield EmailField::new('billingEmail', 'Send invoices and payment reminders to')
            ->setHelp('Leave empty to use the enquiries address.')
            ->hideOnIndex();

        yield FormField::addFieldset('Address')
            ->setHelp('Shown on your website with a map, and used by Google and AI assistants to know where you are.');
        yield TextField::new('streetAddress', 'Street and number')->hideOnIndex();
        yield TextField::new('postalCode', 'Postal code')->hideOnIndex();
        yield TextField::new('city', 'Town');
        yield CountryField::new('country')->hideOnIndex();
        yield BooleanField::new('showStreetAddress', 'Show the street on the website')
            ->setHelp('Working from home? Switch this off: the website and map then only show your town.')
            ->renderAsSwitch(false)
            ->hideOnIndex();

        yield FormField::addTab('Work area', 'fa fa-map-location-dot')
            ->setHelp('Where you take on jobs. Shown on your homepage, with your contact details and in the footer, and passed on to Google and AI assistants.');
        yield ChoiceField::new('trades', 'Your trades')
            ->setChoices(Trade::choices())
            ->allowMultipleChoices()
            ->renderExpanded()
            ->setHelp('Customers find you in the CasitaFix search by these trades and by your town.')
            ->hideOnIndex();
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

        if (Crud::PAGE_EDIT === $pageName) {
            yield from $this->mcpFields();
        }

        if ($isSuperAdmin) {
            yield FormField::addTab('Billing', 'fa fa-credit-card')
                ->setHelp('Charged automatically through Mollie on the 1st of every month. If a payment fails, reminders go out right away, after 2 weeks and after a month; a week after the final reminder the site goes offline.');
            yield BooleanField::new('billingExempt', 'Free account (never charged)')->renderAsSwitch(false)->hideOnIndex();
            yield MoneyField::new('monthlyPrice', 'Monthly price excl. VAT')
                ->setCurrency('EUR')
                ->setStoredAsCents()
                ->setRequired(false)
                ->setHelp('Leave empty for the standard price.')
                ->hideOnIndex();
            yield DateField::new('billingStartsOn', 'Free until (first charge on)')
                ->setHelp('Set automatically to the 1st of the month after the client was added.')
                ->hideOnIndex();
            yield TextField::new('mandateDescription', 'Automatic payment')
                ->formatValue(static fn (?string $value) => $value ?? 'Not set up')
                ->setDisabled()
                ->hideWhenCreating();
            yield DateTimeField::new('suspendedAt', 'Offline for non-payment since')->setDisabled()->hideWhenCreating()->hideOnIndex();


            yield FormField::addTab('Look & Google', 'fab fa-google');
            yield ColorField::new('primaryColor', 'Brand colour')->hideOnIndex();

            yield FormField::addFieldset('Google Search Console')
                ->setHelp('Set up automatically with "Connect Google" (and retried by app:google:connect --all) once the domain points to the server.');
            yield ChoiceField::new('searchConsoleStatus', 'Search Console')
                ->renderAsBadges([
                    SearchConsoleStatus::NotConnected->value => 'secondary',
                    SearchConsoleStatus::AwaitingSite->value => 'warning',
                    SearchConsoleStatus::Connected->value => 'success',
                    SearchConsoleStatus::Failed->value => 'danger',
                ])
                ->hideOnForm();
            yield TextField::new('searchConsoleMessage', 'Last result')->onlyOnDetail();
            yield DateTimeField::new('googleCheckedAt', 'Last checked')->onlyOnDetail();
            yield TextField::new('googleSiteVerification', 'Extra Search Console code (optional)')
                ->setHelp('Only needed to verify the site for another Google account by hand: the content value of its google-site-verification meta tag.')
                ->hideOnIndex();

            yield FormField::addFieldset('Map & Google Business Profile')
                ->setHelp('The map on the website uses OpenStreetMap; its position is looked up from the address automatically.');
            yield UrlField::new('googleBusinessUrl', 'Google Business Profile link')
                ->setHelp('Share link of the Maps listing (business.google.com). Shown as a "See us on Google Maps" link, and tells Google the site and listing belong together.')
                ->hideOnIndex();
            yield NumberField::new('latitude')->setNumDecimals(6)->onlyOnDetail();
            yield NumberField::new('longitude')->setNumDecimals(6)->onlyOnDetail();

            yield FormField::addFieldset('Analytics');
            yield TextField::new('plausibleDomain', 'Plausible analytics domain')
                ->setHelp('Cookieless analytics, no consent banner needed.')
                ->hideOnIndex();
        }
    }

    /** Settings → AI assistant: how to connect an AI assistant (MCP) to the client's data. */
    private function mcpFields(): iterable
    {
        $tenant = $this->getContext()?->getEntity()->getInstance();
        if (!$tenant instanceof Tenant) {
            return;
        }

        yield FormField::addTab('AI assistant', 'fa fa-robot')
            ->setHelp('Let an AI assistant (Claude, ChatGPT, ...) manage your website through MCP: your business details and texts, '
                .'services, finished jobs, enquiries and reviews. Add the server URL and your token as a "custom connector" in the assistant. '
                .'The token only gives access to your own data. Keep it secret, like a password.');
        yield TextField::new('mcpServerUrl', 'MCP server URL')
            ->setFormTypeOptions(['mapped' => false, 'data' => $this->generateUrl('mcp', [], UrlGeneratorInterface::ABSOLUTE_URL), 'disabled' => true]);
        yield TextField::new('mcpTokenStatus', 'Token')
            ->setFormTypeOptions(['mapped' => false, 'disabled' => true, 'data' => $tenant->hasMcpToken()
                ? \sprintf('Created on %s. Only shown once; lost it? Use "Reset MCP token" at the top, or "Remove MCP token" to switch access off.', $tenant->getMcpTokenCreatedAt()?->format('d-m-Y H:i'))
                : 'No token yet, so no MCP access. Use "Generate MCP token" at the top.'])
            ->setHelp('Send it as the header <code>Authorization: Bearer &lt;token&gt;</code>.');
    }

    /** "Generate MCP token" and "Reset MCP token": a new token replaces the old one, shown once. */
    #[AdminRoute('/{entityId}/mcp-token', name: 'mcp_token', options: ['methods' => ['POST']])]
    public function createMcpToken(AdminContext $context): Response
    {
        $tenant = $context->getEntity()->getInstance();
        \assert($tenant instanceof Tenant);
        $this->denyAccessUnlessGranted(TenantAccessVoter::ACCESS, $tenant);

        $token = $tenant->createMcpToken();
        $this->entityManager->flush();

        // EasyAdmin renders flashes as raw HTML; the token is hex, so safe to show as is
        $this->addFlash('success', \sprintf(
            '<strong>Your new MCP token:</strong> <code style="user-select: all">%s</code><br>Copy it now: it is only shown once. Any older token no longer works.',
            $token,
        ));

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::EDIT)->setEntityId($tenant->getId())->generateUrl());
    }

    /** "Remove MCP token": no token, no MCP access. */
    #[AdminRoute('/{entityId}/mcp-token/remove', name: 'mcp_token_remove', options: ['methods' => ['POST']])]
    public function removeMcpToken(AdminContext $context): Response
    {
        $tenant = $context->getEntity()->getInstance();
        \assert($tenant instanceof Tenant);
        $this->denyAccessUnlessGranted(TenantAccessVoter::ACCESS, $tenant);

        $tenant->removeMcpToken();
        $this->entityManager->flush();
        $this->addFlash('success', 'MCP token removed. AI assistants no longer have access.');

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::EDIT)->setEntityId($tenant->getId())->generateUrl());
    }

    /** Looks up the map position again when the address changed. */
    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Tenant && $entityInstance->hasAddress() && !$entityInstance->hasCoordinates()) {
            $result = $this->onboarding->locate($entityInstance);
            // Clients don't need to know about API keys; the website falls back to the address text
            if ($result->hasProblems() && $this->isGranted(User::ROLE_SUPER_ADMIN)) {
                $this->flashResult($result);
            }
        }
    }

    #[AdminRoute('/{entityId}/connect-google', name: 'connect_google', options: ['methods' => ['POST']])]
    public function connectGoogle(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_SUPER_ADMIN);
        $tenant = $context->getEntity()->getInstance();
        \assert($tenant instanceof Tenant);

        $this->flashResult($this->onboarding->connectGoogle($tenant));

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($tenant->getId())->generateUrl());
    }

    #[IsGranted(User::ROLE_SUPER_ADMIN)]
    #[AdminRoute('/{id}/send-payment-setup', name: 'send_payment_setup')]
    public function sendPaymentSetup(#[MapEntity] Tenant $tenant): Response
    {
        if (null === $tenant->getBillingRecipient()) {
            $this->addFlash('danger', 'Add an email address for this client first.');
        } elseif (!$this->billing->sendSetupEmail($tenant)) {
            $this->addFlash('danger', \sprintf('The email could not be sent. Send the client this link yourself: %s', $this->billing->payUrl($tenant)));
        } else {
            $this->addFlash('success', \sprintf('Payment setup email sent to %s. The link: %s', $tenant->getBillingRecipient(), $this->billing->payUrl($tenant)));
        }

        return $this->redirectToRoute('admin_tenant_index');
    }
}
