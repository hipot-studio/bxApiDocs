<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Counter;

use Bitrix\Socialnetwork\V2\Internal\Entity\Counter;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterCollection;
use Bitrix\Socialnetwork\V2\Internal\Entity\CounterColor;
use Bitrix\Socialnetwork\V2\Internal\Integration\Tasks;
use Bitrix\Socialnetwork\V2\Internal\Integration\Calendar;
use Bitrix\Socialnetwork\V2\Internal\Integration\Im;

class ProjectCounterService
{
	public function __construct(
		private readonly Tasks\Service\Counter\ProjectCounterService $tasksCounterService,
		private readonly Calendar\Service\GroupCounterService $calendarCounterService,
		private readonly Im\Service\ChatCounterService $chatCounterService,
	)
	{
	}

	public function getCounters(array $projectIds, int $userId): CounterCollection
	{
		$calendarMap = $this->buildMap($this->calendarCounterService->getTotalGroupCounters($userId, $projectIds));
		$taskCounterMap = $this->buildMap($this->tasksCounterService->getCounters($projectIds, $userId));
		$chatCounterMap = $this->buildMap(
			$this->chatCounterService->getCounterCollectionByGroupIds($userId, $projectIds),
		);

		$groupCounterCollection = new CounterCollection();

		foreach ($projectIds as $projectId)
		{
			$taskCounter = $taskCounterMap[$projectId] ?? null;
			$calendarCounter = $calendarMap[$projectId] ?? null;
			$chatCounter = $chatCounterMap[$projectId] ?? null;

			$value = $this->extractValue($taskCounter, $calendarCounter, $chatCounter);
			$color = $this->extractColor($value, $taskCounter);

			$groupCounterCollection->add(new Counter(
				groupId: $projectId,
				value: $value,
				color: $color,
			));
		}

		return $groupCounterCollection;
	}

	/**
	 * @return Counter[]
	 */
	private function buildMap(CounterCollection $collection): array
	{
		$counterMap = [];
		foreach ($collection->getEntities() as $counter)
		{
			$counterMap[$counter->groupId] = $counter;
		}

		return $counterMap;
	}

	private function extractValue(
		?Counter $taskCounter,
		?Counter $calendarCounter,
		?Counter $chatCounter,
	): int
	{
		return
			($taskCounter?->value ?? 0)
			+ ($calendarCounter?->value ?? 0)
			+ ($chatCounter?->value ?? 0);
	}

	private function extractColor(int $value, ?Counter $taskCounter): ?CounterColor
	{
		if ($taskCounter?->color && $taskCounter->color !== CounterColor::Gray)
		{
			return $taskCounter->color;
		}

		return $value > 0 ? CounterColor::Success : null;
	}
}
