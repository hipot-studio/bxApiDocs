<?php

declare(strict_types=1);

namespace Bitrix\AI\Agents;

use Bitrix\AI\Analytics\FeatureReleaseTracker;
use Bitrix\Main\Loader;

/**
 * Repairs the memory of release analytics on a portal where the updater could not fill it.
 *
 * The updater bans reporting in that case, and only a filled memory lifts the ban. Without this
 * agent the ban would hold until some future update happened to carry a seeding block again, so
 * the analytics of the module would stay silent for an unbounded time.
 *
 * Registered by the updater only on that branch, and it removes itself once the memory is filled.
 */
final class FeatureReleaseSeed
{
	/**
	 * No leading backslash on purpose: the agent renews itself by this very string, and a name
	 * that differs from the registered one by a slash gives a second agent after the update is
	 * re-fetched. Built from `self::class` so a rename cannot break the pair.
	 */
	public const AGENT_CALL = self::class . '::run();';

	private const MODULE_ID = 'ai';

	/**
	 * @return string Own call to run again, or an empty string to be removed.
	 */
	public static function run(): string
	{
		if (!Loader::includeModule(self::MODULE_ID))
		{
			// The module is broken or gone: reporting stays banned, so try again later.
			return self::AGENT_CALL;
		}

		(new FeatureReleaseTracker(self::MODULE_ID))->seedSentMarkers();

		return '';
	}
}
