<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Trigger\Schedule;

use Bitrix\Bizproc\Infrastructure\Agent\Trigger\ScheduledTriggerAgent;
use Bitrix\Bizproc\Internal\Entity\Trigger\SyncAgentData;
use Bitrix\Bizproc\Public\Service\Trigger\ScheduledTriggerService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

class ScheduledTriggerAgentSyncService
{
	private const MODULE_ID = 'bizproc';

	private const DEFAULT_INTERVAL_SECONDS = 60;


	public function __construct(private readonly ScheduledTriggerService $service)
	{
	}

	/**
	 * Ensure that the agent is scheduled to run at the optimal time based on the nearest scheduled trigger and a safety buffer.
	 * If the agent is already scheduled but with a later execution time or inactive
	 * it will be updated to run sooner and activated.
	 *
	 * @return int|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function syncAgentSchedule(): ?int
	{
		$nextRunAt = $this->service->getNextAgentRunAt();
		$agent = ScheduledTriggerAgent::getCurrentAgent();

		if ($nextRunAt === null)
		{
			\CAgent::Delete((int)$agent['ID']);

			return null;
		}

		$nextExecTimestamp = $nextRunAt->getTimestamp();

		if ($agent === null)
		{
			$agentId = $this->addAgent($nextRunAt);

			return isset($agentId) ? (int)$agentId : null;
		}

		$existingTimestamp = isset($agent['NEXT_EXEC']) ? strtotime((string)$agent['NEXT_EXEC']) : false;

		if (
			$existingTimestamp === false
			|| abs($existingTimestamp - $nextExecTimestamp) <= self::DEFAULT_INTERVAL_SECONDS
		)
		{
			return (int)$agent['ID'];
		}

		\CAgent::Delete((int)$agent['ID']);
		$agentId = $this->addAgent($nextRunAt);

		return isset($agentId) ? (int)$agentId : null;
	}

	/**
	 * @param DateTime $nextExec
	 *
	 * @return false|int|null
	 */
	private function addAgent(DateTime $nextExec): false|int|null
	{
		return \CAgent::AddAgent(
			name: ScheduledTriggerAgent::getName(),
			module: self::MODULE_ID,
			interval: self::DEFAULT_INTERVAL_SECONDS,
			next_exec: $nextExec,
			existError: false,
		);
	}
}