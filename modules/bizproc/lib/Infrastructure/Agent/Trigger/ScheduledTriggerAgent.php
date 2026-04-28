<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Infrastructure\Agent\Trigger;

use Bitrix\Bizproc\Infrastructure\Agent\BaseAgent;
use Bitrix\Bizproc\Internal\Service\Trigger\Schedule\ScheduledTriggerAgentSyncService;
use Bitrix\Bizproc\Public\Service\Trigger\ScheduledTriggerService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\SystemException;
use Recurr\Exception\InvalidArgument;
use Recurr\Exception\InvalidRRule;
use Recurr\Exception\InvalidWeekday;

class ScheduledTriggerAgent extends BaseAgent
{
	private const DEFAULT_LIMIT = 200;
	private const MODULE_ID = 'bizproc';

	/**
	 * @return string
	 * @throws ArgumentException
	 * @throws CircularDependencyException
	 * @throws InvalidArgument
	 * @throws InvalidRRule
	 * @throws InvalidWeekday
	 * @throws ObjectNotFoundException
	 * @throws ObjectPropertyException
	 * @throws PersistenceException
	 * @throws ServiceNotFoundException
	 * @throws SystemException
	 */
	public static function run(): string
	{
		$service = ServiceLocator::getInstance()->get(ScheduledTriggerService::class);

		if (!$service instanceof ScheduledTriggerService)
		{
			return static::next();
		}

		$currentAgentId = static::getCurrentAgentId();

		if ($currentAgentId === null)
		{
			return static::next();
		}

		$service->runDueSchedules(self::DEFAULT_LIMIT);

		$newAgentId = ServiceLocator::getInstance()->get(ScheduledTriggerAgentSyncService::class)?->syncAgentSchedule();

		if ($currentAgentId === $newAgentId)
		{
			return static::next();
		}

		return '';
	}

	public static function getName(): string
	{
		return parent::next();
	}

	/**
	 * Fetches the current agent data from the database based on the agent's name and module ID
	 *
	 * @return array{
	 *     ID: string,
	 *     NAME: string,
	 *     MODULE_ID: string,
	 *     NEXT_EXEC: string,
	 * }|null
	 */
	public static function getCurrentAgent(): ?array
	{
		$agent = \CAgent::GetList(
			[],
			[
				'=NAME' => ScheduledTriggerAgent::getName(),
				'=MODULE_ID' => self::MODULE_ID,
			],
		)->Fetch();

		return is_array($agent) ? $agent : null;
	}

	private static function getCurrentAgentId(): ?int
	{
		$agent = static::getCurrentAgent();

		if ($agent === null || !isset($agent['ID']))
		{
			return null;
		}

		return (int)$agent['ID'];
	}
}
