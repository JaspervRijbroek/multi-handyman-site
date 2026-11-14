<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Billing\BillingService;
use App\Entity\Charge;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\MoneyField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\ChoiceFilter;
use EasyCorp\Bundle\EasyAdminBundle\Filter\EntityFilter;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Super admins: every monthly charge of every client. Read-only, except
 * "Mark as paid" for money received outside Mollie (e.g. a bank transfer).
 */
#[IsGranted(User::ROLE_SUPER_ADMIN)]
class ChargeCrudController extends AbstractCrudController
{
    private const array STATUSES = ['Processing' => Charge::PENDING, 'Paid' => Charge::PAID, 'Open (failed)' => Charge::FAILED];

    public static function getEntityFqcn(): string
    {
        return Charge::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Payment')
            ->setEntityLabelInPlural('Payments')
            ->setDefaultSort(['period' => 'DESC', 'id' => 'DESC']);
    }

    public function configureActions(Actions $actions): Actions
    {
        $markPaid = Action::new('markPaid', 'Mark as paid', 'fa fa-check')
            ->linkToCrudAction('markPaid')
            ->displayIf(static fn (Charge $charge) => !$charge->isPaid())
            ->askConfirmation('Mark this payment as paid? Use this for money received outside Mollie, e.g. a bank transfer.');

        return $actions
            ->disable(Action::NEW, Action::EDIT, Action::DELETE)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $markPaid)
            ->add(Crud::PAGE_DETAIL, $markPaid);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add(EntityFilter::new('tenant', 'Client'))
            ->add(ChoiceFilter::new('status')->setChoices(self::STATUSES));
    }

    public function configureFields(string $pageName): iterable
    {
        yield AssociationField::new('tenant', 'Client');
        yield DateField::new('period', 'Month')->setFormat('MMMM yyyy');
        yield TextField::new('invoiceNumber', 'Invoice');
        yield MoneyField::new('totalAmount', 'Amount')->setCurrencyPropertyPath('currency')->setStoredAsCents();
        yield MoneyField::new('netAmount', 'Excl. VAT')->setCurrencyPropertyPath('currency')->setStoredAsCents()->onlyOnDetail();
        yield TextField::new('vatRate', 'VAT %')->onlyOnDetail();
        yield ChoiceField::new('status')->setChoices(self::STATUSES)->renderAsBadges([
            Charge::PENDING => 'secondary',
            Charge::PAID => 'success',
            Charge::FAILED => 'danger',
        ]);
        yield TextField::new('failureReason', 'Reason');
        yield IntegerField::new('reminderStage', 'Reminders sent');
        yield DateTimeField::new('failedAt', 'Failed at')->onlyOnDetail();
        yield DateTimeField::new('lastReminderAt', 'Last reminder')->onlyOnDetail();
        yield DateTimeField::new('paidAt', 'Paid at');
        yield TextField::new('facturaDirectaInvoiceId', 'FacturaDirecta invoice')->onlyOnDetail();
        yield TextField::new('molliePaymentId', 'Mollie payment')->onlyOnDetail();
    }

    #[AdminRoute('/{id}/mark-paid', name: 'mark_paid')]
    public function markPaid(#[MapEntity] Charge $charge, BillingService $billing): Response
    {
        if (!$charge->isPaid()) {
            $billing->markPaidManually($charge);
            $this->addFlash('success', \sprintf('%s for %s marked as paid.', $charge->getPeriodLabel(), $charge->getTenant()->getName()));
        }

        return $this->redirectToRoute('admin_charge_index');
    }
}
