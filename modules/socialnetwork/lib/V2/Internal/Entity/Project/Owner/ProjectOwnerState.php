<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project\Owner;

class ProjectOwnerState
{
	public function __construct(
		public readonly int $projectId,
		public readonly int $ownerId,
		public readonly string $ownerActive,
		public readonly int $ownerRelationUserId,
		public readonly array $projectFields,
	)
	{
	}

	public function isConsistent(): bool
	{
		return $this->ownerId > 0
			&& $this->ownerActive === 'Y'
			&& $this->ownerRelationUserId === $this->ownerId;
	}
}
