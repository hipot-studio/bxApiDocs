<?php

namespace Bitrix\BIConnector\Integration\Superset\Recovery;

use Bitrix\BIConnector\Integration\Superset\Agent;

/**
 * Owns the recovery agent name and every CAgent interaction of the recovery series.
 *
 * The name must be built here only: a different spelling (leading slash and so on) creates
 * a duplicate row in b_agent that no longer can be managed.
 */
final class StatusRecoveryScheduler
{
	private const MODULE_ID = 'biconnector';

	/**
	 * Fallback interval for the agent row. The real delay between attempts is set through
	 * setExecutionPeriod() before the agent returns its own name.
	 */
	private const DEFAULT_INTERVAL = 1800;

	public static function getAgentName(): string
	{
		return Agent::class . '::recoverSupersetStatus();';
	}

	/**
	 * Schedules the next attempt. Must not be called from inside the agent itself:
	 * CAgent::AddAgent() does not update NEXT_EXEC of an existing row with the same name,
	 * so the agent reschedules itself through setExecutionPeriod() instead.
	 *
	 * @return bool Whether the agent row is in place after the call. The caller owns the series state
	 *              and must drop it when the attempt could not be scheduled.
	 */
	public static function schedule(int $delaySeconds): bool
	{
		self::cancel();

		// Returns the row id, an existing row included, or false when the row could not be created.
		$agentId = \CAgent::AddAgent(
			name: self::getAgentName(),
			module: self::MODULE_ID,
			period: 'N',
			interval: self::DEFAULT_INTERVAL,
			active: 'Y',
			next_exec: \ConvertTimeStamp(time() + \CTimeZone::GetOffset() + $delaySeconds, 'FULL'),
			// A parallel hit may have scheduled the agent between cancel() and this call. One row is
			// exactly what we need, so an existing row is a valid outcome, not an application error.
			existError: false,
		);

		return (int)$agentId > 0;
	}

	/**
	 * Whether the agent row of the series exists.
	 *
	 * Lets the caller detect a series left without an agent: the process may die between opening
	 * the series and scheduling its first attempt, and such a series would never retry.
	 */
	public static function isScheduled(): bool
	{
		$agent = \CAgent::GetList(
			['ID' => 'ASC'],
			[
				'=NAME' => self::getAgentName(),
				'MODULE_ID' => self::MODULE_ID,
			],
		)->fetch();

		return is_array($agent);
	}

	public static function cancel(): void
	{
		\CAgent::RemoveAgent(self::getAgentName(), self::MODULE_ID);
	}

	/**
	 * Sets the interval the kernel uses to reschedule an agent that returned its own name.
	 *
	 * @see \CAgent::ExecuteAgents()
	 */
	public static function setExecutionPeriod(int $delaySeconds): void
	{
		global $pPERIOD;

		$pPERIOD = $delaySeconds;
	}
}
