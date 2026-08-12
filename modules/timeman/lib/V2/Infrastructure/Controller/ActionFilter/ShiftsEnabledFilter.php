<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Timeman\V2\Public\Provider\SettingsProvider;

final class ShiftsEnabledFilter extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!(new SettingsProvider())->isShiftsEnabled())
		{
			$this->addError(new Error('Work shift option is disabled', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
