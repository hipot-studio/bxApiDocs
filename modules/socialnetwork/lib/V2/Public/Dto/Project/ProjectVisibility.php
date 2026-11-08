<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto\Project;

/**
 * The project fields a view permission decision needs, carried together so that the identifier and
 * the flag always describe the same row.
 */
class ProjectVisibility
{
	public function __construct(
		public readonly int $projectId,
		public readonly bool $isVisible,
	)
	{
	}
}
