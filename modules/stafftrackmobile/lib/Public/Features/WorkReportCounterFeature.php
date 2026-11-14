<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

/**
 * The switch of the counter of the unsent work report: while it is off the counter is neither synced nor
 * recalculated, and the sync puts the written one out. On unless switched off.
 */
final class WorkReportCounterFeature extends FeatureFlag
{
	private const MODULE_ID = 'stafftrackmobile';
	private const OPTION_NAME = 'work_report_counter_enabled';

	public function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'Y') !== 'N';
	}

	public function enable(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_NAME]);
	}

	public function disable(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'N');
	}
}
