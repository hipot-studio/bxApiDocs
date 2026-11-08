<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Support;

/**
 * Asks the BI instance to start or tells its status once a hit, whatever number of builder calls the hit runs.
 *
 * A hit of MCP runs one tool, so it asks once. A batch of REST V3 runs up to fifty calls in one hit one after
 * another, and while the instance prepares each of them would send a startup request of its own to the proxy.
 * So the first call of the hit that needs the instance asks, a failed attempt included, and the other calls
 * of the hit go on with the status it left.
 */
final class BuilderSupersetStartup
{
	private static bool $asked = false;

	/**
	 * @param \Closure(): void $start
	 */
	public static function once(\Closure $start): void
	{
		if (self::$asked)
		{
			return;
		}

		self::$asked = true;
		$start();
	}

	/**
	 * Starts the next hit in a process that serves several of them, as a test case does.
	 */
	public static function forget(): void
	{
		self::$asked = false;
	}
}
