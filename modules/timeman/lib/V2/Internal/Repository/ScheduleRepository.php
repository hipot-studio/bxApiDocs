<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

use Bitrix\Timeman\Provider\Schedule\ScheduleProvider;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\Schedule;
use Bitrix\Timeman\V2\Internal\Entity\Schedule\ScheduleCollection;
use Bitrix\Timeman\V2\Internal\Repository\Mapper\ScheduleMapper;

final class ScheduleRepository
{
	private readonly ScheduleProvider $scheduleProvider;

	public function __construct(?ScheduleProvider $scheduleProvider = null)
	{
		$this->scheduleProvider = $scheduleProvider
			?? DependencyManager::getInstance()->getScheduleProvider();
	}

	public function findByUserId(int $userId): ScheduleCollection
	{
		if ($userId <= 0)
		{
			return new ScheduleCollection();
		}

		$legacySchedules = $this->scheduleProvider->findSchedulesCollectionByUserId($userId);
		$schedules = new ScheduleCollection();

		foreach ($legacySchedules as $legacySchedule)
		{
			$schedules->add(
				new Schedule(
					id: (int)$legacySchedule->getId(),
					name: (string)$legacySchedule->getName(),
					type: ScheduleMapper::normalizeType((string)$legacySchedule->getScheduleType()),
				),
			);
		}

		return $schedules;
	}

	public function hasFlextime(ScheduleCollection $schedules): bool
	{
		return $schedules->find(
			static fn(Schedule $schedule): bool => ScheduleMapper::normalizeType($schedule->type) === Schedule::TYPE_FLEXTIME,
		) !== null;
	}
}
