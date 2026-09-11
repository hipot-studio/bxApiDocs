<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Selfhost\License;

use Bitrix\Main\Application;
use CAgent;

/**
 * Puts the agents of the local mode into the schedule and takes them out of it.
 *
 * Removing the old row before adding a new one keeps a repeated call from leaving a second agent, and the whole
 * pair is done under a lock, because two parallel requests would otherwise both find nothing to remove and both
 * add a row.
 */
final class AgentInstaller
{
	private const MODULE_ID = 'biconnector';
	private const LOCK_KEY = 'biconnector_selfhost_license_agent_install';
	private const SORT = 100;

	public static function install(string $agentName, int $interval): void
	{
		$connection = Application::getConnection();
		// Failing to take the lock means another request is installing the very same agent right now, so there is
		// nothing left to do here.
		if (!$connection->lock(self::LOCK_KEY, 0))
		{
			return;
		}

		try
		{
			CAgent::RemoveAgent($agentName, self::MODULE_ID);
			CAgent::AddAgent($agentName, self::MODULE_ID, 'N', $interval, '', 'Y', '', self::SORT, false, false);
		}
		finally
		{
			$connection->unlock(self::LOCK_KEY);
		}
	}

	public static function remove(string $agentName): void
	{
		CAgent::RemoveAgent($agentName, self::MODULE_ID);
	}
}
