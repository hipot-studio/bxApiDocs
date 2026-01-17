<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Mapper;

use Bitrix\Tasks\V2\Internal\Entity;

class TimerMapper
{
	public function mapToCollection(array $timers): Entity\Task\TimerCollection
	{
		$entities = [];
		foreach ($timers as $timer)
		{
			$entities[] = $this->mapToEntity($timer);
		}

		return new Entity\Task\TimerCollection(...$entities);
	}

	public function mapToEntity(array $timer): Entity\Task\Timer
	{
		return new Entity\Task\Timer(
			userId: Entity\Task\Timer::mapInteger($timer, 'USER_ID'),
			taskId: Entity\Task\Timer::mapInteger($timer, 'TASK_ID'),
			startedAtTs: Entity\Task\Timer::mapInteger($timer, 'TIMER_STARTED_AT'),
			seconds: Entity\Task\Timer::mapInteger($timer, 'TIMER_ACCUMULATOR'),
		);
	}

	public function mapFromEntity(Entity\Task\Timer $timer): array
	{
		return [
			'USER_ID' => $timer->userId,
			'TASK_ID' => $timer->taskId,
			'TIMER_STARTED_AT' => $timer->startedAtTs,
			'TIMER_ACCUMULATOR' => $timer->seconds,
		];
	}
}
