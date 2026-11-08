<?php

namespace Bitrix\Calendar\Core\Queue\Agent;

use Bitrix\Calendar\Core\Queue\Rule\RuleMaster;
use Bitrix\Main\Application;
use CAgent;
use CTimeZone;
use RuntimeException;

class QueueRoutingAgent
{
	private const MODULE_ID = 'calendar';
	private const SCHEDULE_LOCK_NAME = self::class . ':schedule';
	private const CONTINUATION_DELAY = 1;
	private const RECOVERY_DELAY = 60;

	public static function run(int $maxMessageId, int $afterMessageId): string
	{
		(new RuleMaster())->run($maxMessageId, $afterMessageId);

		return '';
	}

	/**
	 * Unlike the bounded continuation this agent carries no window: it re-reads whatever is still
	 * unrouted and keeps itself registered until the queue is drained. That is what makes a lost
	 * routing signal — a busy lock or a continuation that could not be registered — recoverable.
	 */
	public static function recover(): string
	{
		$ruleMaster = new RuleMaster();
		$ruleMaster->run();

		return $ruleMaster->hasUnroutedMessages() ? self::getRecoveryName() : '';
	}

	public static function schedule(int $maxMessageId, int $afterMessageId): void
	{
		self::add(self::getName($maxMessageId, $afterMessageId), self::CONTINUATION_DELAY);
	}

	public static function scheduleRecovery(): void
	{
		self::add(self::getRecoveryName(), self::RECOVERY_DELAY);
	}

	public static function getName(int $maxMessageId, int $afterMessageId): string
	{
		return sprintf(
			'\\%s::run(%d, %d);',
			self::class,
			$maxMessageId,
			$afterMessageId,
		);
	}

	public static function getRecoveryName(): string
	{
		return sprintf('\\%s::recover();', self::class);
	}

	private static function add(string $agentName, int $delay): void
	{
		if (self::isScheduled($agentName))
		{
			return;
		}

		$connection = Application::getConnection();
		if (!$connection->lock(self::SCHEDULE_LOCK_NAME, 5))
		{
			if (self::isScheduled($agentName))
			{
				return;
			}

			throw new RuntimeException('Could not lock queue routing agent registration.');
		}

		try
		{
			$agentId = CAgent::AddAgent(
				$agentName,
				self::MODULE_ID,
				'N',
				$delay,
				'',
				'Y',
				ConvertTimeStamp(time() + CTimeZone::GetOffset() + $delay, 'FULL'),
				100,
				false,
				false,
			);
			if ($agentId === false)
			{
				throw new RuntimeException('Could not register queue routing agent.');
			}
		}
		finally
		{
			$connection->unlock(self::SCHEDULE_LOCK_NAME);
		}
	}

	private static function isScheduled(string $agentName): bool
	{
		return (bool)CAgent::GetList(
			[],
			[
				'MODULE_ID' => self::MODULE_ID,
				'=NAME' => $agentName,
			],
		)->Fetch();
	}
}
