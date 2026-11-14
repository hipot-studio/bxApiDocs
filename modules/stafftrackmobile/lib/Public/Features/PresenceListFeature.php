<?php

declare(strict_types=1);

namespace Bitrix\StaffTrackMobile\Public\Features;

use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

/**
 * The rollout switch of the presence list. The client reads it from `isPresenceListEnabled` of the map answer;
 * while it is off the list action answers `PRESENCE_UNAVAILABLE` and reads nothing.
 */
final class PresenceListFeature extends FeatureFlag
{
	private const MODULE_ID = 'stafftrackmobile';
	private const OPTION_NAME = 'presence_list_enabled';

	public function isEnabled(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set(self::MODULE_ID, self::OPTION_NAME, 'Y');
	}

	public function disable(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_NAME]);
	}
}
