<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\StructureSync\Async;

class LegacyDepartmentDeletedMessage extends AbstractStructureSyncMessage
{
	public function __construct(
		public readonly int $collabId,
		public readonly int $departmentId,
		public readonly int $initiatorId,
	)
	{
	}

	public function getItemId(): string
	{
		return "legacy_department_{$this->collabId}_{$this->departmentId}";
	}
}
