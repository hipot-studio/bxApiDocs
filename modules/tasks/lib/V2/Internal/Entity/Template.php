<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Tasks\V2\Internal\Entity\Template\PermissionCollection;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;
use Bitrix\Tasks\V2\Internal\Entity\Template\Type;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\Template\TagCollection;

class Template extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $id = null,
		public readonly ?Task $task = null,
		public readonly ?string $title = null,
		public readonly ?string $description = null,
		public readonly ?User $creator = null,
		public readonly ?UserCollection $responsibleCollection = null,
		public readonly ?int $deadlineAfter = null,
		public readonly ?int $startDatePlanAfter = null,
		public readonly ?int $endDatePlanAfter = null,
		public readonly ?bool $allowsChangeDeadline = null,
		public readonly ?bool $allowsTimeTracking = null,
		public readonly ?bool $matchesWorkTime = null,
		public readonly ?bool $needsControl = null,
		public readonly ?bool $replicate = null,
		public readonly ?ReplicateParams $replicateParams = null,
		public readonly ?Group $group = null,
		public readonly ?int $estimatedTime = null,
		public readonly ?array $dependsOn = null,
		public readonly ?TagCollection $tags = null,
		public readonly ?Task $parent = null,
		public readonly ?self $base = null,
		public readonly ?Type $type = null,
		public readonly ?array $fileIds = null,
		public readonly ?array $checklist = null,
		public readonly ?Priority $priority = null,
		public readonly ?UserCollection $accomplices = null,
		public readonly ?UserCollection $auditors = null,
		public readonly ?string $siteId = null,
		public readonly ?PermissionCollection $permissions = null,
		public readonly ?UserFieldCollection $userFields = null,
		public readonly ?array $crmItemIds = null,
		public readonly ?bool $containsRelatedTasks = null,
		public readonly ?bool $containsChecklist = null,
		public readonly ?bool $containsSubTemplates = null,
		public readonly ?string $link = null,
		public readonly ?string $archiveLink = null,
		public readonly ?array $rights = null,
		public readonly ?bool $multitask = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'task' => $this->task?->toArray(),
			'title' => $this->title,
			'description' => $this->description,
			'creator' => $this->creator?->toArray(),
			'responsibleCollection' => $this->responsibleCollection?->toArray(),
			'deadlineAfter' => $this->deadlineAfter,
			'startDatePlanAfter' => $this->startDatePlanAfter,
			'endDatePlanAfter' => $this->endDatePlanAfter,
			'allowsChangeDeadline' => $this->allowsChangeDeadline,
			'allowsTimeTracking' => $this->allowsTimeTracking,
			'matchesWorkTime' => $this->matchesWorkTime,
			'needsControl' => $this->needsControl,
			'replicate' => $this->replicate,
			'replicateParams' => $this->replicateParams?->toArray(),
			'group' => $this->group?->toArray(),
			'estimatedTime' => $this->estimatedTime,
			'dependsOn' => $this->dependsOn,
			'tags' => $this->tags?->toArray(),
			'parent' => $this->parent?->toArray(),
			'base' => $this->base?->toArray(),
			'type' => $this->type?->value,
			'fileIds' => $this->fileIds,
			'checklist' => $this->checklist,
			'priority' => $this->priority?->value,
			'accomplices' => $this->accomplices?->toArray(),
			'auditors' => $this->auditors?->toArray(),
			'siteId' => $this->siteId,
			'permissions' => $this->permissions?->toArray(),
			'userFields' => $this->userFields?->toArray(),
			'crmItemIds' => $this->crmItemIds,
			'containsRelatedTasks' => $this->containsRelatedTasks,
			'containsChecklist' => $this->containsChecklist,
			'containsSubTemplates' => $this->containsSubTemplates,
			'link' => $this->link,
			'archiveLink' => $this->archiveLink,
			'rights' => $this->rights,
			'multitask' => $this->multitask,
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: static::mapInteger($props, 'id'),
			task: static::mapEntity($props, 'task', Task::class),
			title: static::mapString($props, 'title'),
			description: static::mapString($props, 'description'),
			creator: static::mapEntity($props, 'creator', User::class),
			responsibleCollection: static::mapEntityCollection($props, 'responsibleCollection', UserCollection::class),
			deadlineAfter: static::mapInteger($props, 'deadlineAfter'),
			startDatePlanAfter: static::mapInteger($props, 'startDatePlanAfter'),
			endDatePlanAfter: static::mapInteger($props, 'endDatePlanAfter'),
			allowsChangeDeadline: static::mapBool($props, 'allowsChangeDeadline'),
			allowsTimeTracking: static::mapBool($props, 'allowsTimeTracking'),
			matchesWorkTime: static::mapBool($props, 'matchesWorkTime'),
			needsControl: static::mapBool($props, 'needsControl'),
			replicate: static::mapBool($props, 'replicate'),
			replicateParams: static::mapValueObject($props, 'replicateParams', ReplicateParams::class),
			group: static::mapEntity($props, 'group', Group::class),
			estimatedTime: static::mapInteger($props, 'estimatedTime'),
			dependsOn: static::mapArray($props, 'dependsOn'),
			tags: static::mapEntityCollection($props, 'tags', TagCollection::class),
			parent: static::mapEntity($props, 'parent', Task::class),
			base: static::mapEntity($props, 'base', self::class),
			type: static::mapBackedEnum($props, 'type', Type::class),
			fileIds: static::mapArray($props, 'fileIds'),
			checklist: static::mapArray($props, 'checklist'),
			priority: static::mapBackedEnum($props, 'priority', Priority::class),
			accomplices: static::mapEntityCollection($props, 'accomplices', UserCollection::class),
			auditors: static::mapEntityCollection($props, 'auditors', UserCollection::class),
			siteId: static::mapString($props, 'siteId'),
			permissions: static::mapEntityCollection($props, 'permissions', PermissionCollection::class),
			userFields: static::mapEntityCollection($props, 'userFields', UserFieldCollection::class),
			crmItemIds: static::mapArray($props, 'crmItemIds'),
			containsRelatedTasks: static::mapBool($props, 'containsRelatedTasks'),
			containsChecklist: static::mapBool($props, 'containsChecklist'),
			containsSubTemplates: static::mapBool($props, 'containsSubTemplates'),
			link: static::mapString($props, 'link'),
			archiveLink: static::mapString($props, 'archiveLink'),
			rights: static::mapArray($props, 'rights'),
			multitask: static::mapBool($props, 'multitask'),
		);
	}
}
