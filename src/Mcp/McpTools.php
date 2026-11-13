<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Enquiry;
use App\Entity\Project;
use App\Entity\Review;
use App\Entity\Service;
use App\Entity\Tenant;
use App\Entity\TenantOwnedInterface;
use App\Entity\Trade;
use App\Onboarding\ClientOnboarding;
use App\Tenant\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The tools a client's AI assistant can use. They only ever touch the active
 * tenant's data: the tenant filter limits every query, TenantGuardListener blocks
 * cross-tenant writes, and records are checked once more by owner below.
 *
 * Roughly the same as what a client can do in the admin, except uploading photos.
 */
final readonly class McpTools
{
    /** Business fields the assistant may read and change: argument name => Tenant property */
    private const array BUSINESS_FIELDS = [
        'name' => 'name',
        'phone' => 'phone',
        'whatsapp' => 'whatsapp',
        'email' => 'email',
        'service_area' => 'serviceArea',
        'street_address' => 'streetAddress',
        'postal_code' => 'postalCode',
        'city' => 'city',
        'show_street_address' => 'showStreetAddress',
        'trades' => 'trades',
        'tagline' => 'tagline',
        'intro' => 'intro',
        'about' => 'about',
        'legal_name' => 'legalName',
        'tax_id' => 'taxId',
        'legal_address' => 'legalAddress',
    ];

    private const array TRANSLATED_BUSINESS_FIELDS = ['tagline', 'intro', 'about'];

    /** Project photos: same folder as the admin uploads, max 5 MB (fits in PHP's default 8 MB request limit as base64) */
    private const int MAX_PHOTO_BYTES = 5 * 1024 * 1024;
    private const array PHOTO_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(
        private TenantContext $tenantContext,
        private EntityManagerInterface $entityManager,
        private ValidatorInterface $validator,
        private ClientOnboarding $onboarding,
        #[Autowire('%kernel.project_dir%/public/uploads/projects')]
        private string $photoDir,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        $text = ['type' => 'string'];
        $nullableText = ['type' => ['string', 'null']];
        $id = ['type' => 'integer', 'description' => 'ID from the matching list tool'];
        $translated = static fn (string $description) => [
            'type' => 'object',
            'additionalProperties' => ['type' => 'string'],
            'description' => $description.' Map of language code to text, e.g. {"en": "...", "es": "..."}. '
                .'Only the languages you send are changed; send an empty text to remove a language.',
        ];
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false];
        $write = ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false];
        $delete = ['readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => false];

        return [
            self::tool('get_business', 'Get the business details and website texts, plus the website languages and the possible trades.', [], [], $readOnly),
            self::tool('update_business', 'Change business details or website texts. Only the fields you send are changed; send null to clear a field.', [
                'name' => $text + ['description' => 'Business name'],
                'phone' => $nullableText,
                'whatsapp' => $nullableText + ['description' => 'International format, e.g. +34 600 123 456'],
                'email' => $nullableText + ['description' => 'Enquiries from the contact form are sent here'],
                'service_area' => $nullableText + ['description' => 'Towns and areas you work in, separated by commas'],
                'street_address' => $nullableText,
                'postal_code' => $nullableText,
                'city' => $nullableText + ['description' => 'Town'],
                'show_street_address' => ['type' => 'boolean', 'description' => 'False: the website only shows the town'],
                'trades' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Trade::values()], 'description' => 'Replaces the list of trades the business is found for in the search'],
                'tagline' => $translated('Short tagline.'),
                'intro' => $translated('Introduction on the homepage.'),
                'about' => $translated('About the business.'),
                'legal_name' => $nullableText + ['description' => 'Legal name (owner or company), for the legal notice'],
                'tax_id' => $nullableText + ['description' => 'NIF / NIE / CIF'],
                'legal_address' => $nullableText + ['description' => 'Registered address'],
            ], [], $write),

            self::tool('list_services', 'List the services shown on the website.', [], [], $readOnly),
            self::tool('save_service', 'Add a service, or change one when an ID is given.', [
                'id' => ['description' => 'Leave out to add a new service'] + $id,
                'title' => $translated('Title, e.g. Painting.'),
                'description' => $translated('Description.'),
                'position' => ['type' => 'integer', 'description' => 'Order on the website, lowest first'],
            ], [], $write),
            self::tool('delete_service', 'Delete a service.', ['id' => $id], ['id'], $delete),

            self::tool('list_projects', 'List the finished jobs (portfolio) on the website, newest first.', [], [], $readOnly),
            self::tool('save_project', 'Add a finished job, or change one when an ID is given.', [
                'id' => ['description' => 'Leave out to add a new job'] + $id,
                'title' => $translated('What was done.'),
                'description' => $translated('Details.'),
                'completed_at' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'Finished on, YYYY-MM-DD'],
                'published' => ['type' => 'boolean', 'description' => 'Show on the website'],
                'photo_base64' => ['type' => 'string', 'contentEncoding' => 'base64', 'description' => 'Photo of the finished work: a JPG, PNG or WebP file, base64-encoded, max 5 MB. Replaces the current photo.'],
            ], [], $write),
            self::tool('delete_project', 'Delete a finished job.', ['id' => $id], ['id'], $delete),

            self::tool('list_enquiries', 'List messages from the contact form, newest first (at most 50).', [
                'handled' => ['type' => 'boolean', 'description' => 'Only handled (true) or open (false) enquiries; leave out for all'],
            ], [], $readOnly),
            self::tool('set_enquiry_handled', 'Mark an enquiry as handled or open.', [
                'id' => $id,
                'handled' => ['type' => 'boolean'],
            ], ['id', 'handled'], $write),

            self::tool('list_reviews', 'List customer reviews, newest first (at most 50). Reviews cannot be written or edited, only shown, hidden or deleted.', [
                'published' => ['type' => 'boolean', 'description' => 'Only shown (true) or hidden (false) reviews; leave out for all'],
            ], [], $readOnly),
            self::tool('set_review_published', 'Show a review on the website, or hide it.', [
                'id' => $id,
                'published' => ['type' => 'boolean'],
            ], ['id', 'published'], $write),
            self::tool('delete_review', 'Delete a review, e.g. spam.', ['id' => $id], ['id'], $delete),
        ];
    }

    public function has(string $name): bool
    {
        return \in_array($name, array_column($this->definitions(), 'name'), true);
    }

    /**
     * @param array<string, mixed> $args
     *
     * @throws McpToolException
     */
    public function call(string $name, array $args): array
    {
        $tenant = $this->tenantContext->require();

        return match ($name) {
            'get_business' => $this->business($tenant),
            'update_business' => $this->updateBusiness($tenant, $args),
            'list_services' => array_map($this->service(...), $this->entityManager->getRepository(Service::class)->findBy([], ['position' => 'ASC', 'id' => 'ASC'])),
            'save_service' => $this->saveService($args),
            'delete_service' => $this->delete(Service::class, $args),
            'list_projects' => array_map($this->project(...), $this->entityManager->getRepository(Project::class)->findBy([], ['completedAt' => 'DESC', 'id' => 'DESC'])),
            'save_project' => $this->saveProject($args),
            'delete_project' => $this->delete(Project::class, $args),
            'list_enquiries' => array_map($this->enquiry(...), $this->entityManager->getRepository(Enquiry::class)->findBy(self::filter($args, 'handled'), ['createdAt' => 'DESC'], 50)),
            'set_enquiry_handled' => $this->enquiry($this->find(Enquiry::class, $args)->setHandled((bool) ($args['handled'] ?? false)), flush: true),
            'list_reviews' => array_map($this->review(...), $this->entityManager->getRepository(Review::class)->findBy(self::filter($args, 'published'), ['createdAt' => 'DESC'], 50)),
            'set_review_published' => $this->review($this->find(Review::class, $args)->setPublished((bool) ($args['published'] ?? false)), flush: true),
            'delete_review' => $this->delete(Review::class, $args),
        };
    }

    private function business(Tenant $tenant): array
    {
        $data = ['website' => $tenant->getPrimaryDomain() ? 'https://'.$tenant->getPrimaryDomain()->getHostname() : null];
        foreach (self::BUSINESS_FIELDS as $arg => $property) {
            $data[$arg] = $this->entityManager->getClassMetadata(Tenant::class)->getFieldValue($tenant, $property);
        }

        return $data + [
            'languages' => $tenant->getLocales(),
            'possible_trades' => Trade::values(),
        ];
    }

    private function updateBusiness(Tenant $tenant, array $args): array
    {
        foreach (array_intersect_key($args, self::BUSINESS_FIELDS) as $arg => $value) {
            $setter = 'set'.ucfirst(self::BUSINESS_FIELDS[$arg]);

            $value = match (true) {
                \in_array($arg, self::TRANSLATED_BUSINESS_FIELDS, true) => $this->translated($tenant->{'get'.ucfirst(self::BUSINESS_FIELDS[$arg])}(), $value, $arg),
                'show_street_address' === $arg => (bool) $value,
                'trades' === $arg => \is_array($value) ? $value : throw new McpToolException('trades must be a list.'),
                null === $value => null,
                \is_scalar($value) => trim((string) $value),
                default => throw new McpToolException(\sprintf('%s must be a text.', $arg)),
            };

            $tenant->$setter('' === $value ? null : $value);
        }

        $this->flush($tenant);

        // Same as saving the settings in the admin: a changed address gets a new map position
        if ($tenant->hasAddress() && !$tenant->hasCoordinates()) {
            $this->onboarding->locate($tenant);
        }

        return $this->business($tenant);
    }

    private function saveService(array $args): array
    {
        $service = isset($args['id']) ? $this->find(Service::class, $args) : (new Service())->setTenant($this->tenantContext->require());
        $this->applyTexts($service, $args);
        if (\array_key_exists('position', $args)) {
            $service->setPosition((int) $args['position']);
        }

        return $this->service($service, flush: true);
    }

    private function saveProject(array $args): array
    {
        $project = isset($args['id']) ? $this->find(Project::class, $args) : (new Project())->setTenant($this->tenantContext->require());
        $this->applyTexts($project, $args);
        if (\array_key_exists('completed_at', $args)) {
            $project->setCompletedAt(self::date($args['completed_at']));
        }
        if (\array_key_exists('published', $args)) {
            $project->setPublished((bool) $args['published']);
        }

        if (!isset($args['photo_base64'])) {
            return $this->project($project, flush: true);
        }

        $oldPhoto = $project->getImageName();
        $project->setImageName($this->savePhoto($args['photo_base64']));
        try {
            $data = $this->project($project, flush: true);
        } catch (McpToolException $e) {
            @unlink($this->photoDir.'/'.$project->getImageName());
            throw $e;
        }
        if ($oldPhoto) {
            @unlink($this->photoDir.'/'.basename($oldPhoto));
        }

        return $data;
    }

    /** Checks the photo and stores it under a random name, like the admin upload does. Returns the file name. */
    private function savePhoto(mixed $base64): string
    {
        $bytes = \is_string($base64) ? base64_decode(preg_replace('/^data:[^,]*,/', '', $base64), true) : false;
        if (false === $bytes || '' === $bytes) {
            throw new McpToolException('photo_base64 must be a base64-encoded image.');
        }
        if (\strlen($bytes) > self::MAX_PHOTO_BYTES) {
            throw new McpToolException('The photo is too big: max 5 MB.');
        }

        $mime = (@getimagesizefromstring($bytes) ?: [])['mime'] ?? null;
        $extension = self::PHOTO_TYPES[$mime] ?? throw new McpToolException('The photo must be a JPG, PNG or WebP image.');

        $name = bin2hex(random_bytes(20)).'.'.$extension;
        if (!is_dir($this->photoDir) && !mkdir($this->photoDir, 0775, true) && !is_dir($this->photoDir)) {
            throw new \RuntimeException('Cannot create '.$this->photoDir);
        }
        if (false === file_put_contents($this->photoDir.'/'.$name, $bytes)) {
            throw new \RuntimeException('Cannot save the photo.');
        }

        return $name;
    }

    private function applyTexts(Service|Project $entity, array $args): void
    {
        if (\array_key_exists('title', $args)) {
            $entity->setTitle($this->translated($entity->getTitle(), $args['title'], 'title'));
        }
        if (\array_key_exists('description', $args)) {
            $entity->setDescription($this->translated($entity->getDescription(), $args['description'], 'description'));
        }
    }

    /** @param class-string<TenantOwnedInterface> $class */
    private function delete(string $class, array $args): array
    {
        $entity = $this->find($class, $args);
        $this->entityManager->remove($entity);
        $this->entityManager->flush();

        return ['deleted' => (int) $args['id']];
    }

    /**
     * @template T of TenantOwnedInterface
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function find(string $class, array $args): object
    {
        $id = $args['id'] ?? null;
        // The tenant filter already hides other clients' records; the owner check is a second lock
        $entity = \is_int($id) || ctype_digit((string) $id) ? $this->entityManager->find($class, (int) $id) : null;

        if (!$entity instanceof TenantOwnedInterface || $entity->getTenant()?->getId() !== $this->tenantContext->require()->getId()) {
            throw new McpToolException(\sprintf('No %s with ID %s.', strtolower((new \ReflectionClass($class))->getShortName()), json_encode($id)));
        }

        return $entity;
    }

    /** Merges the sent languages into the current texts. */
    private function translated(array $current, mixed $value, string $field): array
    {
        if (!\is_array($value)) {
            throw new McpToolException(\sprintf('%s must be a map of language code to text, e.g. {"en": "..."}.', $field));
        }

        $languages = $this->tenantContext->require()->getLocales();
        if ($unknown = array_diff(array_keys($value), $languages)) {
            throw new McpToolException(\sprintf('%s: the website has no language %s. Its languages: %s.', $field, implode(', ', $unknown), implode(', ', $languages)));
        }

        return array_merge($current, array_map(static fn ($text) => (string) $text, $value));
    }

    /** Validates like the admin forms do, then saves. */
    private function flush(object $entity): void
    {
        $violations = $this->validator->validate($entity);
        if (\count($violations) > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = ($violation->getPropertyPath() ? $violation->getPropertyPath().': ' : '').$violation->getMessage();
            }
            throw new McpToolException(implode(' ', $messages));
        }

        $this->entityManager->persist($entity);
        $this->entityManager->flush();
    }

    private function service(Service $service, bool $flush = false): array
    {
        if ($flush) {
            $this->flush($service);
        }

        return [
            'id' => $service->getId(),
            'title' => $service->getTitle(),
            'description' => $service->getDescription(),
            'position' => $service->getPosition(),
        ];
    }

    private function project(Project $project, bool $flush = false): array
    {
        if ($flush) {
            $this->flush($project);
        }

        return [
            'id' => $project->getId(),
            'title' => $project->getTitle(),
            'description' => $project->getDescription(),
            'completed_at' => $project->getCompletedAt()?->format('Y-m-d'),
            'published' => $project->isPublished(),
            'has_photo' => null !== $project->getImageName(),
            'photo_url' => $this->photoUrl($project),
        ];
    }

    private function photoUrl(Project $project): ?string
    {
        $domain = $this->tenantContext->require()->getPrimaryDomain();

        return $project->getImageName() && $domain
            ? 'https://'.$domain->getHostname().'/uploads/projects/'.rawurlencode($project->getImageName())
            : null;
    }

    private function enquiry(Enquiry $enquiry, bool $flush = false): array
    {
        if ($flush) {
            $this->flush($enquiry);
        }

        return [
            'id' => $enquiry->getId(),
            'received_at' => $enquiry->getCreatedAt()->format(\DATE_ATOM),
            'name' => $enquiry->getName(),
            'contact' => $enquiry->getContact(),
            'message' => $enquiry->getMessage(),
            'language' => $enquiry->getLocale(),
            'handled' => $enquiry->isHandled(),
        ];
    }

    private function review(Review $review, bool $flush = false): array
    {
        if ($flush) {
            $this->flush($review);
        }

        return [
            'id' => $review->getId(),
            'received_at' => $review->getCreatedAt()->format(\DATE_ATOM),
            'author' => $review->getAuthorName() ?: 'Anonymous',
            'town' => $review->getTown(),
            'rating' => $review->getRating(),
            'text' => $review->getText(),
            'language' => $review->getLocale(),
            'published' => $review->isPublished(),
            'job' => $review->getProject()?->getId(),
        ];
    }

    private static function filter(array $args, string $field): array
    {
        return \array_key_exists($field, $args) && null !== $args[$field] ? [$field => (bool) $args[$field]] : [];
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        $date = \is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $date ?: throw new McpToolException('completed_at must be a date like 2026-05-31.');
    }

    private static function tool(string $name, string $description, array $properties, array $required, array $annotations): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'inputSchema' => [
                'type' => 'object',
                'properties' => $properties ?: new \stdClass(),
                'required' => $required,
                'additionalProperties' => false,
            ],
            'annotations' => $annotations,
        ];
    }
}
