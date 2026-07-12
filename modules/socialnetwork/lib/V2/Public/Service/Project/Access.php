<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Service\Project;

use Bitrix\Socialnetwork\V2\Internal\Access\Service\ProjectAccessService;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class Access
{
	private readonly ProjectAccessService $projectAccessService;

	public function __construct()
	{
		$this->projectAccessService = Container::getInstance()->getProjectAccessService();
	}

	public function canCreate(int $userId, ?string $siteId = null): bool
	{
		return $this->projectAccessService->canCreate($userId, $siteId);
	}

	public function canInvite(int $userId, int $projectId): bool
	{
		return $this->projectAccessService->canInvite($userId, $projectId);
	}

	/**
	 * @param int $userId
	 * @param (int|string)[] $projectIds
	 * @return int[]
	 */
	public function filterInvitable(int $userId, array $projectIds): array
	{
		$result = [];
		foreach ($projectIds as $projectId)
		{
			if ($this->projectAccessService->canInvite($userId, (int)$projectId))
			{
				$result[] = (int)$projectId;
			}
		}

		return $result;
	}
}
