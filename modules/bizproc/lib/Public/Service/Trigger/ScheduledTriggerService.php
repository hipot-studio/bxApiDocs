<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\Trigger;

use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduleRunnerService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;

class ScheduledTriggerService
{
	public function __construct(private readonly ScheduleRunnerService $runner)
	{
	}

	/**
	 * @throws ObjectPropertyException
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws PersistenceException
	 * @throws ArgumentException
	 * @throws SystemException
	 * @throws InvalidWeekday
	 */
	public function runDueSchedules(int $limit = 200): int
	{
		return $this->runner->runDueSchedules($limit);
	}

	/**
	 *
	 * Returns the DateTime of the next scheduled trigger run in UTC timezone. If there are no scheduled triggers, returns null
	 *
	 * @return DateTime|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getNextAgentRunAt(): ?DateTime
	{
		return $this->runner->getNextAgentRunAt();
	}
}
