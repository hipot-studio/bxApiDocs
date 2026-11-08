<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\Intranet\AnnualSummary;

use Bitrix\Main\Config\Option;

/**
 * Where the annual summary of the module is switched on. The flag decides whether the numbers are
 * counted and not whether the module takes part in the run: the provider is handed to the agent
 * whatever the flag says, see InitSummaryProviders.
 *
 * A class of its own rather than a check inside the provider: the name of the option is a contract
 * set by hand on a portal, and the file of the provider returns before declaring its class where
 * intranet is missing - the switch of this module has no business depending on a foreign one.
 */
final class FeatureFlag
{
	public const MODULE_ID = 'bizproc';
	public const OPTION_NAME = 'annual_summary_enabled';

	private const DEFAULT_VALUE = 'N';

	public static function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, self::DEFAULT_VALUE) === 'Y';
	}
}
