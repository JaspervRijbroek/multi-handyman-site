<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Signup;
use App\Entity\SignupStatus;
use App\Entity\Tenant;
use App\Entity\User;
use App\Onboarding\ClientOnboarding;
use App\Onboarding\OnboardingException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FieldCollection;
use EasyCorp\Bundle\EasyAdminBundle\Collection\FilterCollection;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Dto\EntityDto;
use EasyCorp\Bundle\EasyAdminBundle\Dto\SearchDto;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\CountryField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TelephoneField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Super admins only. Sign-ups from /register wait here for approval; "Approve"
 * creates the client, domain(s) and login and connects Google in one click.
 * "Add client" (new) is the same form for clients you sign up yourself: saving
 * it approves it straight away.
 */
class SignupCrudController extends AbstractCrudController
{
    use OnboardingFlashesTrait;

    public function __construct(
        private readonly ClientOnboarding $onboarding,
        private readonly AdminUrlGenerator $adminUrlGenerator,
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Signup::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Sign-up')
            ->setEntityLabelInPlural('Sign-ups')
            ->setEntityPermission(User::ROLE_SUPER_ADMIN)
            ->setPageTitle(Crud::PAGE_NEW, 'Add client')
            ->setPageTitle(Crud::PAGE_DETAIL, fn (Signup $signup) => $signup->getBusinessName())
            ->setHelp(Crud::PAGE_NEW, 'Saving creates the client, its domain and login right away, emails the client a link to choose a password, and connects Google.')
            ->setHelp(Crud::PAGE_INDEX, 'New clients sign up at /register. Check their details and approve: the client, domain, login and Google setup are created automatically.')
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setSearchFields(['businessName', 'contactName', 'email', 'phone', 'city', 'domain']);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters->add(ChoiceFilter::new('status')->setChoices(array_combine(
            array_map(static fn (SignupStatus $s) => $s->label(), SignupStatus::cases()),
            SignupStatus::cases(),
        )));
    }

    public function configureActions(Actions $actions): Actions
    {
        $isPending = static fn (Signup $signup) => $signup->isPending();

        $approve = Action::new('approve', 'Approve', 'fa fa-check')
            ->linkToCrudAction('approve')
            ->renderAsForm()
            ->asSuccessAction()
            ->displayIf($isPending);

        $reject = Action::new('reject', 'Reject', 'fa fa-xmark')
            ->linkToCrudAction('reject')
            ->renderAsForm()
            ->asDangerAction()
            ->askConfirmation('Reject the sign-up of %entity_name%? Nothing is created, and the stored password is removed.')
            ->displayIf($isPending);

        $viewClient = Action::new('viewClient', 'Open client', 'fa fa-building')
            ->linkToUrl(fn (Signup $signup) => $this->tenantUrl($signup->getTenant()))
            ->displayIf(static fn (Signup $signup) => null !== $signup->getTenant());

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $approve)
            ->add(Crud::PAGE_DETAIL, $approve)
            ->add(Crud::PAGE_INDEX, $reject)
            ->add(Crud::PAGE_DETAIL, $reject)
            ->add(Crud::PAGE_INDEX, $viewClient)
            ->add(Crud::PAGE_DETAIL, $viewClient)
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $a) => $a->setLabel('Add client')->setIcon('fa fa-plus'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $a) => $a->displayIf($isPending))
            ->update(Crud::PAGE_DETAIL, Action::EDIT, static fn (Action $a) => $a->displayIf($isPending))
            ->update(Crud::PAGE_NEW, Action::SAVE_AND_RETURN, static fn (Action $a) => $a->setLabel('Create client'))
            ->remove(Crud::PAGE_NEW, Action::SAVE_AND_ADD_ANOTHER)
            ->setPermissions([
                Action::INDEX => User::ROLE_SUPER_ADMIN,
                Action::DETAIL => User::ROLE_SUPER_ADMIN,
                Action::NEW => User::ROLE_SUPER_ADMIN,
                Action::EDIT => User::ROLE_SUPER_ADMIN,
                Action::DELETE => User::ROLE_SUPER_ADMIN,
                'approve' => User::ROLE_SUPER_ADMIN,
                'reject' => User::ROLE_SUPER_ADMIN,
            ]);
    }

    public function configureFields(string $pageName): iterable
    {
        $localeChoices = array_combine(array_map('strtoupper', Tenant::SUPPORTED_LOCALES), Tenant::SUPPORTED_LOCALES);

        yield DateTimeField::new('createdAt', 'Received')->hideOnForm()->setFormat('dd-MM-yyyy HH:mm');
        yield ChoiceField::new('status')
            ->renderAsBadges([
                SignupStatus::Pending->value => 'warning',
                SignupStatus::Approved->value => 'success',
                SignupStatus::Rejected->value => 'secondary',
            ])
            ->hideOnForm();

        yield FormField::addFieldset('Business');
        yield TextField::new('businessName', 'Business name');
        yield TextField::new('contactName', 'Contact person')
            ->setHelp('Also used as the legal name in the legal notice; can be changed later.');
        yield EmailField::new('email', 'Email')
            ->setHelp('Becomes the client\'s login and receives the website enquiries.');
        yield TelephoneField::new('phone');
        yield TelephoneField::new('whatsapp', 'WhatsApp')->hideOnIndex()->setHelp('Leave empty if it is the same as the phone.');
        yield TextField::new('taxId', 'NIF / NIE / CIF')->hideOnIndex();

        yield FormField::addFieldset('Address');
        yield TextField::new('streetAddress', 'Street and number')->hideOnIndex();
        yield TextField::new('postalCode', 'Postal code')->hideOnIndex();
        yield TextField::new('city', 'Town');
        yield CountryField::new('country')->hideOnIndex();
        yield TextField::new('serviceArea', 'Work area')->hideOnIndex()->setHelp('Towns and areas, separated by commas. Defaults to the town.');

        yield FormField::addFieldset('Website');
        yield TextField::new('domain', 'Own domain')
            ->hideOnIndex()
            ->setHelp('e.g. jansklusbedrijf.com; www. is added automatically. Leave empty to use a subdomain of CLIENT_SUBDOMAIN_BASE (if set).');
        yield ChoiceField::new('locales', 'Languages')
            ->setChoices($localeChoices)
            ->allowMultipleChoices()
            ->renderExpanded()
            ->hideOnIndex();
        yield ChoiceField::new('defaultLocale', 'Main language')->setChoices($localeChoices)->hideOnIndex();
        yield ChoiceField::new('billingPlan', 'Plan')
            ->setChoices(['Monthly' => Tenant::PLAN_MONTHLY, 'Yearly' => Tenant::PLAN_YEARLY]);
        yield TextareaField::new('message', 'Message from the client')->hideOnIndex();

        yield AssociationField::new('tenant', 'Client')->hideOnForm();
        yield DateTimeField::new('decidedAt', 'Decided')->onlyOnDetail();
    }

    /** Sign-ups waiting for approval always come first. */
    public function createIndexQueryBuilder(SearchDto $searchDto, EntityDto $entityDto, FieldCollection $fields, FilterCollection $filters): QueryBuilder
    {
        $qb = parent::createIndexQueryBuilder($searchDto, $entityDto, $fields, $filters);
        $sorting = $qb->getDQLPart('orderBy');

        $qb->addSelect('CASE WHEN entity.status = :pending THEN 0 ELSE 1 END AS HIDDEN pendingFirst')
            ->setParameter('pending', SignupStatus::Pending->value)
            ->orderBy('pendingFirst', 'ASC');
        foreach ($sorting as $orderBy) {
            $qb->addOrderBy($orderBy);
        }

        return $qb;
    }

    /** Saving the "Add client" form approves it straight away. */
    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Signup) {
            $this->runApproval($entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof Signup && !$entityInstance->isPending()) {
            throw new AccessDeniedException('Only sign-ups that wait for approval can be changed.');
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    #[AdminRoute('/{entityId}/approve', name: 'approve', options: ['methods' => ['POST']])]
    public function approve(AdminContext $context): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_SUPER_ADMIN);
        $signup = $context->getEntity()->getInstance();
        \assert($signup instanceof Signup);

        $tenant = $this->runApproval($signup);

        return $this->redirect($tenant ? $this->tenantUrl($tenant) : $this->signupUrl($signup));
    }

    #[AdminRoute('/{entityId}/reject', name: 'reject', options: ['methods' => ['POST']])]
    public function reject(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted(User::ROLE_SUPER_ADMIN);
        $signup = $context->getEntity()->getInstance();
        \assert($signup instanceof Signup);

        if ($signup->isPending()) {
            $signup->reject();
            $entityManager->flush();
            $this->addFlash('info', htmlspecialchars(\sprintf('The sign-up of %s was rejected.', $signup->getBusinessName()), \ENT_QUOTES));
        }

        return $this->redirect($this->adminUrlGenerator->setController(self::class)->setAction(Action::INDEX)->unset('entityId')->generateUrl());
    }

    private function runApproval(Signup $signup): ?Tenant
    {
        try {
            $result = $this->onboarding->approve($signup);
        } catch (OnboardingException $e) {
            $this->flashProblems($e);

            return null;
        }

        $this->flashResult($result);

        return $result->tenant;
    }

    private function tenantUrl(?Tenant $tenant): string
    {
        return $this->adminUrlGenerator->setController(TenantCrudController::class)->setAction(Action::DETAIL)->setEntityId($tenant?->getId())->generateUrl();
    }

    private function signupUrl(Signup $signup): string
    {
        return $this->adminUrlGenerator->setController(self::class)->setAction(Action::DETAIL)->setEntityId($signup->getId())->generateUrl();
    }
}
