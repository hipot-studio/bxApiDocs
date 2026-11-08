<?php

namespace Bitrix\BIConnector\Agent;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * One-shot cleanup of the usage log tail left by the shorter retention period.
 *
 * The updater cannot do this itself: it runs before the module files are copied, so the old
 * threshold is still on disk and the tail between the old and the new period would survive.
 * The agent starts shortly after the update — already on the new code — and deletes itself
 * by returning an empty string, so the regular daily cleanup agent stays the only periodic one.
 */
final class UsageStatTailCleanUpAgent
{
	public static function run(): string
	{
		if (!Loader::includeModule('biconnector'))
		{
			return '';
		}

		if (!Application::getConnection()->isTableExists('b_biconnector_log'))
		{
			return '';
		}

		// the retention threshold lives in the regular agent, so it is not duplicated here
		\Bitrix\BIConnector\LogTable::cleanUpAgent();

		return '';
	}
}
