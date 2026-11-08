<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Permission\Dto;

/**
 * A result exists for every requested container, including missing ones.
 */
final readonly class GroupViewResult
{
	public function __construct(
		public int $groupId,
		public bool $isFound = false,
		public bool $canView = false,
		public bool $isVisible = false,
		public ?string $role = null,
		public bool $isExtranet = false,
		public ?string $type = null,
		public bool $isProject = false,
		public bool $isNewProject = false,
	)
	{
	}

	public static function notFound(int $groupId): self
	{
		return new self(groupId: $groupId);
	}
}
