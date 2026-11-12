<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Project;
use App\Review\ReviewRequestResult;
use App\Review\ReviewRequestSender;
use App\Security\TenantAccessVoter;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\FormField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Service\Attribute\Required;

class ProjectCrudController extends AbstractTenantCrudController
{
    private ReviewRequestSender $reviewRequests;

    #[Required]
    public function setReviewRequestSender(ReviewRequestSender $reviewRequests): void
    {
        $this->reviewRequests = $reviewRequests;
    }

    public static function getEntityFqcn(): string
    {
        return Project::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return parent::configureCrud($crud)
            ->setEntityLabelInSingular('Project')
            ->setEntityLabelInPlural('Projects')
            ->setDefaultSort(['completedAt' => 'DESC', 'id' => 'DESC'])
            ->setPageTitle(Crud::PAGE_INDEX, 'Finished jobs')
            ->setPageTitle(Crud::PAGE_NEW, 'Add a finished job');
    }

    public function configureActions(Actions $actions): Actions
    {
        $sendReviewLink = Action::new('sendReviewLink', 'Send review link', 'fa fa-star')
            ->linkToCrudAction('sendReviewLink')
            ->renderAsForm()
            ->askConfirmation(\sprintf('Send the customer a new review link? It works for %d days; an older link stops working.', Project::REVIEW_LINK_DAYS))
            ->displayIf(static fn (Project $project) => null !== $project->getCustomerContact());

        return $actions
            ->add(Crud::PAGE_INDEX, $sendReviewLink)
            ->add(Crud::PAGE_EDIT, $sendReviewLink);
    }

    public function configureFields(string $pageName): iterable
    {
        yield from $this->tenantFields();
        yield ImageField::new('imageName', 'Photo')
            ->setBasePath('uploads/projects')
            ->setUploadDir('public/uploads/projects')
            ->setUploadedFileNamePattern('[randomhash].[extension]')
            ->setRequired(Crud::PAGE_NEW === $pageName)
            ->setHelp('A clear photo of the finished work. JPG or PNG.');
        yield TranslatedField::new('title', 'What did you do?')->setLocales($this->locales());
        yield TranslatedField::new('description', 'Details (optional)')
            ->setLocales($this->locales())
            ->multiline()
            ->hideOnIndex();
        yield DateField::new('completedAt', 'Finished on');
        yield BooleanField::new('published', 'Show on website')->renderAsSwitch(false);

        yield FormField::addFieldset('Ask your customer for a review');
        yield TextField::new('customerContact', 'Customer email or phone (optional)')
            ->hideOnIndex()
            ->setHelp(\sprintf(
                'When you save, your customer gets a link to review this job on your website. It works for %d days. '
                .'Email: we send it for you. Phone (with country code, e.g. +34 600 111 222): you send it on WhatsApp in one tap. '
                .'Their details are removed once they have left a review.',
                Project::REVIEW_LINK_DAYS,
            ));
        yield DateTimeField::new('reviewRequestedAt', 'Review link sent')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        parent::persistEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Project && $entityInstance->getCustomerContact()) {
            $this->sendReviewRequest($entityInstance);
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Send a link when a customer contact is added or changed, not on every save
        $before = $entityManager->getUnitOfWork()->getOriginalEntityData($entityInstance)['customerContact'] ?? null;

        parent::updateEntity($entityManager, $entityInstance);

        if ($entityInstance instanceof Project && $entityInstance->getCustomerContact() && $entityInstance->getCustomerContact() !== $before) {
            $this->sendReviewRequest($entityInstance);
        }
    }

    #[AdminRoute('/{entityId}/send-review-link', name: 'send_review_link', options: ['methods' => ['POST']])]
    public function sendReviewLink(AdminContext $context): Response
    {
        $project = $context->getEntity()->getInstance();
        \assert($project instanceof Project);
        $this->denyAccessUnlessGranted(TenantAccessVoter::ACCESS, $project);

        if ($project->getCustomerContact()) {
            $this->sendReviewRequest($project);
        }

        return $this->redirectToRoute('admin_project_index');
    }

    /** Sends the link and tells the client what happened (EasyAdmin renders flashes as raw HTML). */
    private function sendReviewRequest(Project $project): void
    {
        $result = $this->reviewRequests->send($project);
        $link = htmlspecialchars((string) $result->link, \ENT_QUOTES);
        $contact = htmlspecialchars((string) $project->getCustomerContact(), \ENT_QUOTES);

        match ($result->status) {
            ReviewRequestResult::EMAILED => $this->addFlash('success', \sprintf('Review link emailed to %s.', $contact)),
            ReviewRequestResult::WHATSAPP => $this->addFlash('info', \sprintf(
                '<strong>Send your customer the review link:</strong> <a class="btn btn-success btn-sm" href="%s" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> Send on WhatsApp</a><br>Or send this link yourself: %s',
                htmlspecialchars((string) $result->whatsappUrl, \ENT_QUOTES),
                $link,
            )),
            ReviewRequestResult::EMAIL_FAILED => $this->addFlash('warning', \sprintf('The review link could not be emailed to %s. Send them this link yourself: %s', $contact, $link)),
            default => $this->addFlash('warning', 'No review link sent: your website has no domain yet.'),
        };
    }
}
