<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service;

use Bitrix\Main\Loader;
use Bitrix\Tasks\Internals\Effective;

class ProjectEfficiencyService
{
	/**
	 * Returns efficiency values keyed by project ID.
	 *
	 * @param int[] $projectIds
	 * @return array<int, int> projectId => efficiency (0-100)
	 */
	public function getEfficiency(array $projectIds): array
	{
		if (empty($projectIds) || !Loader::includeModule('tasks'))
		{
			return [];
		}

		return Effective::getAverageEfficiencyForGroups(
			groupIds: $projectIds,
		);
	}
}
