<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Socialnetwork\Helper\Workgroup\Access;
use Bitrix\Socialnetwork\V2\Public\Dto\Project\ProjectVisibility;

class ProjectAccessProvider
{
	public function canView(int $projectId, int $userId, bool $checkAdminSession = true): bool
	{
		return Access::canViewWithMinimalData([
			'groupId' => $projectId,
			'userId' => $userId,
			'checkAdminSession' => $checkAdminSession,
		]);
	}

	/**
	 * Decides the view permission by an already read project, sparing the check a second read.
	 */
	public function canViewProject(ProjectVisibility $project, int $userId, bool $checkAdminSession = true): bool
	{
		return Access::canViewWithMinimalData([
			'groupId' => $project->projectId,
			'visible' => $project->isVisible,
			'userId' => $userId,
			'checkAdminSession' => $checkAdminSession,
		]);
	}
}
