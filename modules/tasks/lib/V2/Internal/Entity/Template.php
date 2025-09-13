<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Tasks\V2\Internal\Entity\Task\Priority;
use Bitrix\Tasks\V2\Internal\Entity\Template\ReplicateParams;

class Template extends AbstractEntity
{
	public function __construct(
		public readonly ?int            $id = null,
		#[NotEmpty]
		public readonly ?string         $title = null,
		public readonly ?string         $description = null,
		public readonly ?User           $creator = null,
		#[NotEmpty]
		public readonly ?UserCollection $responsibleCollection = null,
		public readonly ?int            $deadlineAfterTs = null,
		public readonly ?int            $startDatePlanTs = null,
		public readonly ?int            $endDatePlanTs = null,
		public readonly ?bool           $replicate = null,
		public readonly ?array          $fileIds = null,
		public readonly ?array          $checklist = null,
		public readonly ?Group          $group = null,
		public readonly ?Priority       $priority = null,
		public readonly ?UserCollection $accomplices = null,
		public readonly ?UserCollection $auditors = null,
		public readonly ?self           $parent = null,
		public readonly ?ReplicateParams $replicateParams = null,
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
			'title' => $this->title,
			'description' => $this->description,
			'creator' => $this->creator?->toArray(),
			'responsibleCollection' => $this->responsibleCollection?->toArray(),
			'deadlineAfterTs' => $this->deadlineAfterTs,
			'startDatePlanTs' => $this->startDatePlanTs,
			'endDatePlanTs' => $this->endDatePlanTs,
			'replicate' => $this->replicate,
			'fileIds' => $this->fileIds,
			'checklist' => $this->checklist,
			'group' => $this->group?->toArray(),
			'priority' => $this->priority?->value,
			'accomplices' => $this->accomplices?->toArray(),
			'auditors' => $this->auditors?->toArray(),
			'parent' => $this->parent?->toArray(),
			'replicateParams' => $this->replicateParams?->toArray(),
		];
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id:              $props['id'] ?? null,
			title:           $props['title'] ?? null,
			description:     $props['description'] ?? null,
			creator:         isset($props['creator']) ? User::mapFromArray($props['creator']) : null,
			responsibleCollection:	isset($props['responsibleCollection']) ? UserCollection::mapFromArray($props['responsibleCollection']) : null,
			deadlineAfterTs: $props['deadlineAfterTs'] ?? null,
			startDatePlanTs: $props['startDatePlanTs'] ?? null,
			endDatePlanTs:   $props['endDatePlanTs'] ?? null,
			replicate: 		 $props['replicate'] ?? null,
			fileIds:         $props['fileIds'] ?? null,
			checklist:       isset($props['checklist']) && is_array($props['checklist']) ? $props['checklist'] : null,
			group:           isset($props['group']) ? Group::mapFromArray($props['group']) : null,
			priority:        Priority::tryFrom(($props['priority'] ?? '')),
			accomplices:     isset($props['accomplices']) ? UserCollection::mapFromArray($props['accomplices']) : null,
			auditors:        isset($props['auditors']) ? UserCollection::mapFromArray($props['auditors']) : null,
			parent:          isset($props['parent']) ? static::mapFromArray($props['parent']) : null,
			replicateParams: isset($props['replicateParams']) ? ReplicateParams::mapFromArray($props['replicateParams']) : null,
		);
	}
}
