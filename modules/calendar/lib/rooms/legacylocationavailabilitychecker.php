<?php

namespace Bitrix\Calendar\Rooms;

use Bitrix\Calendar\Core\Event\LegacyLocationAvailabilityCheckerInterface;
use Bitrix\Main\Config\Option;

final class LegacyLocationAvailabilityChecker implements LegacyLocationAvailabilityCheckerInterface
{
	public function isAvailable(string $location, array $fields): bool
	{
		if (Option::get('calendar', 'isOccupancyCheckerEnabled', 'Y', '-') === 'N')
		{
			return true;
		}

		$parsedLocation = Util::parseLocation($location);
		if (empty($parsedLocation['mrid']))
		{
			return true;
		}

		return AccessibilityManager::checkAccessibility($location, ['fields' => $fields]);
	}
}
