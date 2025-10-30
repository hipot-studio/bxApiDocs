<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\CheckList;

use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\V2\Internal\Entity\AttachmentCollection;
use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;

class CheckListItem extends AbstractEntity
{
	public function __construct(
		public readonly ?int $id = null,
		public readonly ?string $nodeId = null,
		public readonly ?string $title = null,
		public readonly ?User $creator = null,
		public readonly ?User $toggledBy = null,
		public readonly ?DateTime $toggledDate = null,
		public readonly ?UserCollection $accomplices = null,
		public readonly ?UserCollection $auditors = null,
		public readonly ?AttachmentCollection $attachments = null,
		public readonly ?bool $isComplete = null,
		public readonly ?bool $isImportant = null,
		public readonly ?int $parentId = null,
		public readonly ?string $parentNodeId = null,
		public readonly ?int $sortIndex = null,
		public readonly ?array $actions = null,
		public readonly ?bool $collapsed = null,
		public readonly ?bool $expanded = null,
	)
	{

	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			id: $props['id'] ?? null,
			nodeId: $props['nodeId'] ?? null,
			title: $props['title'] ?? null,
			creator: isset($props['creator']) ? User::mapFromArray($props['creator']) : null,
			toggledBy: isset($props['toggledBy']) ? User::mapFromArray($props['toggledBy']) : null,
			toggledDate: $props['toggledDate'] ?? null,
			accomplices: isset($props['accomplices']) ? UserCollection::mapFromArray($props['accomplices']) : null,
			auditors: isset($props['auditors']) ? UserCollection::mapFromArray($props['auditors']) : null,
			attachments: isset($props['attachments']) ? AttachmentCollection::mapFromArray($props['attachments']) : null,
			isComplete: $props['isComplete'] ?? null,
			isImportant: $props['isImportant'] ?? null,
			parentId: $props['parentId'] ?? null,
			parentNodeId: $props['parentNodeId'] ?? null,
			sortIndex: $props['sortIndex'] ?? null,
			actions: $props['actions'] ?? null,
			collapsed: $props['collapsed'] ?? null,
			expanded: $props['expanded'] ?? null,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'nodeId' => $this->nodeId,
			'title' => $this->title,
			'creator' => $this->creator?->toArray(),
			'toggledBy' => $this->toggledBy?->toArray(),
			'toggledDate' => $this->toggledDate,
			'accomplices' => $this->accomplices?->toArray(),
			'auditors' => $this->auditors?->toArray(),
			'attachments' => $this->attachments?->toArray(),
			'isComplete' => $this->isComplete,
			'isImportant' => $this->isImportant,
			'parentId' => $this->parentId,
			'parentNodeId' => $this->parentNodeId,
			'sortIndex' => $this->sortIndex,
			'actions' => $this->actions,
			'collapsed' => $this->collapsed,
			'expanded' => $this->expanded,
		];
	}
}