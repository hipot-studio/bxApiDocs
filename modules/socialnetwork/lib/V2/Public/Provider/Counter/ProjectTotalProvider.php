<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Counter;

use Bitrix\Socialnetwork\Helper\User;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Counter\ProjectTotalCounterService;

class ProjectTotalProvider
{
	private readonly ProjectTotalCounterService $counterService;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->counterService = $container->get(ProjectTotalCounterService::class);
	}

	public function getTotal(int $userId): int
	{
		$currentUserId = $this->getCurrentUserId();

		if (!$currentUserId || $userId !== $currentUserId)
		{
			return 0;
		}

		return $this->counterService->getTotal($userId);
	}

	protected function getCurrentUserId(): int
	{
		return User::getCurrentUserId();
	}
}
