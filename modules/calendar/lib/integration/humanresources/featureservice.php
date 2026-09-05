<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Integration\HumanResources;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

class FeatureService
{
	// CFG-01: gates only team visibility in the attendee selector; the backend
	// pipeline is always active. Default off (per-portal Option 'N').
	public static function isTeamsAsAttendeeEnabled(): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		if (!\Bitrix\HumanResources\Config\Feature::instance()->isCrossFunctionalTeamsAvailable())
		{
			return false;
		}

		return Option::get('calendar', 'team_attendees_enabled', 'N') === 'Y';
	}
}
