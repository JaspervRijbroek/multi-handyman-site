<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\Field\TranslatedField;
use App\Entity\Project;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;

class ProjectCrudController extends AbstractTenantCrudController
{
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
    }
}
