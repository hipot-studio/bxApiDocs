<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Infrastructure\ActionFilters;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\StaffTrack\Public\Provider\OfficesAvailabilityProvider;

/**
 * Closes the ajax surface of the office directory by the same switch that hides the map page: while
 * offices are not released, the directory is not reachable around the page either.
 */
final class OfficeUnavailableFilter extends Base
{
	public const ERROR_CODE = 'STAFFTRACK_OFFICES_UNAVAILABLE';

	public function onBeforeAction(Event $event)
	{
		if ((new OfficesAvailabilityProvider())->isAvailable())
		{
			return null;
		}

		$this->addError(new Error('Offices are not available', self::ERROR_CODE));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
