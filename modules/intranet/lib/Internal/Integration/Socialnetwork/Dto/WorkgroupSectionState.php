<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork\Dto;

final readonly class WorkgroupSectionState
{
	public function __construct(
		public int $groupId,
		public bool $isFound,
		public bool $canView,
		public bool $isNewProject,
		public string $canonicalLink = '',
	)
	{
	}

	public function isAccessibleProjectSection(): bool
	{
		return $this->isNewProject && $this->canView;
	}
}
