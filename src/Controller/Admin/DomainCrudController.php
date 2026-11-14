<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Domain;
use App\Entity\User;
use App\Repository\TurnstileWidgetRepository;
use App\Turnstile\TurnstileException;
use App\Turnstile\TurnstileWidgetSync;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly TurnstileWidgetSync $turnstileSync,
        private readonly TurnstileWidgetRepository $turnstileWidgets,
    ) {
    }

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
            ->setHelp('Without https:// or slashes, e.g. jansklusbedrijf.com. Point its DNS (A record) to the server. Clients can add their own domain themselves under "Your domain".');
        yield AssociationField::new('tenant', 'Client');
        yield BooleanField::new('isPrimary', 'Primary')
            ->setHelp('Other hostnames of the same client redirect to the primary one.')
            ->renderAsSwitch(false);

        if ($this->turnstileSync->isEnabled()) {
            yield TextField::new('hostname', 'Turnstile')
                ->onlyOnIndex()
                ->setSortable(false)
                ->formatValue(fn (string $hostname): string => $this->turnstileWidgets->findOneCovering($hostname)
                    ? 'Protected'
                    : 'Not on Cloudflare yet');
        }
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);
        $this->syncTurnstile();
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::updateEntity($entityManager, $entityInstance);
        $this->syncTurnstile();
    }

    public function deleteEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::deleteEntity($entityManager, $entityInstance);
        $this->syncTurnstile();
    }

    /** Add the hostname to Cloudflare Turnstile right away, so the forms on the new site work. */
    private function syncTurnstile(): void
    {
        if (!$this->turnstileSync->isEnabled()) {
            return;
        }

        try {
            $this->turnstileSync->sync();
            $this->addFlash('success', 'Cloudflare Turnstile is up to date with the domains.');
        } catch (TurnstileException $e) {
            $this->addFlash('warning', 'Could not update Cloudflare Turnstile, the forms on this site use only the spam trap until then. Run "app:turnstile:sync" to try again. '.$e->getMessage());
        }
    }
}
