<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Tasks\Service\Counter;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Entity;
use Bitrix\Tasks\Internals\Counter;
use Bitrix\Tasks\Internals\Counter\CounterDictionary;

class ProjectCounterService
{
	public function __construct(
		private readonly CounterFactory $counterFactory,
	)
	{
	}

	public function getCounters(array $projectIds, int $userId): Entity\CounterCollection
	{
		if (empty($projectIds) || !$this->isTasksModuleAvailable())
		{
			return new Entity\CounterCollection();
		}

		$entities = [];
		$userCounter = $this->counterFactory->getCounter($userId);

		foreach ($projectIds as $projectId)
		{
			$projectId = (int)$projectId;
			$entities[$projectId] = $this->getUserCounterValue($userCounter, $projectId);
		}

		return new Entity\CounterCollection(...$entities);
	}

	protected function isTasksModuleAvailable(): bool
	{
		return Loader::includeModule('tasks');
	}

	private function getUserCounterValue(Counter $counter, int $projectId): Entity\Counter
	{
		$color = Entity\CounterColor::Success;

		if (!$projectId)
		{
			return new Entity\Counter(
				groupId: $projectId,
				value: 0,
				color: $color,
			);
		}

		$expired = $counter->get(CounterDictionary::COUNTER_EXPIRED, $projectId);
		$mutedMentioned = $counter->get(CounterDictionary::COUNTER_MUTED_MENTIONED, $projectId);
		$value = $expired + $mutedMentioned;

		if ($expired > 0)
		{
			$color = Entity\CounterColor::Danger;
		}

		return new Entity\Counter(
			groupId: $projectId,
			value: $value,
			color: $color,
		);
	}
}
