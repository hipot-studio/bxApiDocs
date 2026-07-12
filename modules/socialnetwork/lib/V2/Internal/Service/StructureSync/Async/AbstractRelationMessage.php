<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async;

abstract class AbstractRelationMessage extends AbstractStructureSyncMessage
{
	public function __construct(
		public readonly int $nodeId,
		public readonly int $entityId,
		public readonly int $createdBy,
		public readonly bool $withChildNodes,
		public readonly int $offset = 0,
	)
	{
	}

	public function getItemId(): string
	{
		return "relation_{$this->entityId}" . ($this->offset > 0 ? "_{$this->offset}" : '');
	}

	public function withOffset(int $offset): static
	{
		return new static(
			nodeId: $this->nodeId,
			entityId: $this->entityId,
			createdBy: $this->createdBy,
			withChildNodes: $this->withChildNodes,
			offset: $offset,
		);
	}
}
