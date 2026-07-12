<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Grid;

use Bitrix\Socialnetwork\Helper\User;
use Bitrix\Socialnetwork\V2\Internal\DI\Container;
use Bitrix\Socialnetwork\V2\Internal\Service\Counter\ProjectCounterService;
use Bitrix\Socialnetwork\V2\Public\Dto\CounterCollection;
use Bitrix\Socialnetwork\V2\Public\Mapper\CounterMapper;

class ProjectCounterProvider implements CounterProviderInterface
{
	private ProjectCounterService $counterService;
	private CounterMapper $mapper;

	public function __construct()
	{
		$container = Container::getInstance();

		$this->mapper = $container->get(CounterMapper::class);
		$this->counterService = $container->getProjectCounterService();
	}

	public function get(array $groupIds, int $userId): CounterCollection
	{
		$currentUserId = $this->getCurrentUserId();

		if (!$currentUserId || $userId !== $currentUserId)
		{
			return new CounterCollection();
		}

		$entityCounters = $this->counterService->getCounters($groupIds, $userId);

		return $this->mapper->mapToDtoCollection($entityCounters);
	}

	protected function getCurrentUserId(): int
	{
		return User::getCurrentUserId();
	}
}
