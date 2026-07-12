<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider;

use Bitrix\Socialnetwork\V2\Internal\DI\Container;

class CalendarSettingsProvider
{
	public function get(): array
	{
		return Container::getInstance()
			->getCalendarSettingsService()
			->getFormattedSettings()
		;
	}
}
