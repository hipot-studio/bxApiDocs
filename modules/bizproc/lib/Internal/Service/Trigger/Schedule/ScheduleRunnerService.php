<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\Internal\Entity\Trigger\TriggerSchedule;
use Bitrix\Bizproc\Internal\Repository\TriggerScheduleRepository\TriggerScheduleRepository;
use Bitrix\Bizproc\Internal\Service\Trigger\Messenger\Entity\ScheduledTriggerMessage;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;

class ScheduleRunnerService
{
	private const DEFAULT_LIMIT = 200;
	private const SCHEDULED_TRIGGER_QUEUE = 'scheduled_trigger_queue';
	private const TIMEZONE = 'UTC';

	public function __construct(
		private readonly ScheduleCalculator $calculator,
		private readonly TriggerScheduleRepository $repository,
	)
	{
	}

	/**
	 * @param int $limit
	 *
	 * @return int
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws PersistenceException
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function runDueSchedules(int $limit = self::DEFAULT_LIMIT): int
	{
		$now = $this->getCurrentDateTimeInUTC();

		$schedules = $this->repository->getDueSchedules($now, $limit);
		if ($schedules->isEmpty())
		{
			return 0;
		}

		foreach ($schedules as $schedule)
		{
			$this->runSchedule($schedule, $now);
		}

		return count($schedules);
	}

	/**
	 * @param TriggerSchedule $schedule
	 * @param DateTime $now
	 *
	 * @throws ArgumentException
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws SystemException
	 * @throws SqlQueryException
	 */
	private function runSchedule(TriggerSchedule $schedule, DateTime $now): void
	{
		$scheduleData = $schedule->getScheduleData();
		$scheduledAt = $schedule->getNextRunAt();

		if ($scheduledAt === null)
		{
			return;
		}

		if ($schedule->getId() === null)
		{
			return;
		}

		$message = new ScheduledTriggerMessage(
			scheduleId: $schedule->getId(),
			templateId: $schedule->getTemplateId(),
			triggerName: $schedule->getTriggerName(),
			scheduledAt: $scheduledAt->format(DateTime::getFormat()),
		);

		try
		{
			$message->send(self::SCHEDULED_TRIGGER_QUEUE);
		}
		catch (\Throwable)
		{
		}

		$nextRunAt = $this->calculator->calculateNextRunAt($scheduleData->toArray(), $now);
		$this->repository->actualizeSchedule($schedule->getId(), $scheduledAt, $now, $nextRunAt);
	}

	/**
	 * Calculates the next optimal time for the agent to run,
	 *
	 * @return DateTime|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getNextAgentRunAt(): ?DateTime
	{
		$nearest = $this->repository->getNearestNextRunAt();
		if ($nearest === null)
		{
			return null;
		}

		$nextRunAt = DateTime::createFromTimestamp($nearest->getTimestamp());
		$nextRunAt->setTimeZone(new \DateTimeZone(self::TIMEZONE));

		return $nextRunAt;
	}

	/**
	 * @return DateTime
	 */
	public function getCurrentDateTimeInUTC(): DateTime
	{
		$now = new DateTime();
		$now->setTimeZone(new \DateTimeZone(self::TIMEZONE));

		return $now;
	}
}
