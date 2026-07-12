<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Counter;

use Bitrix\Socialnetwork\V2\Internal\Integration\Im;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar;
use Bitrix\Socialnetwork\V2\Internal\Factory\UserRegistryFactory;

class ProjectTotalCounterService
{
	public function __construct(
		private readonly Calendar\Service\GroupCounterService $calendarCounterService,
		private readonly Tasks\Service\Counter\ProjectTotalService $taskCounterService,
		private readonly Im\Service\ChatCounterService $chatCounterService,
		private readonly UserRegistryFactory $userRegistryFactory,
	)
	{
	}

	public function getTotal(int $userId): int
	{
		$userRegistry = $this->userRegistryFactory->factory($userId);
		$groupIds = array_keys($userRegistry->getUserProjects());

		$calendarCount = $this->calendarCounterService->getTotalCountForGroupIds($userId, $groupIds);
		$taskCount = $this->taskCounterService->getTotalForUser($userId);
		$chatCount = $this->chatCounterService->getTotalCountForGroupIds($userId, $groupIds);

		return $chatCount + $calendarCount + $taskCount;
	}
}
