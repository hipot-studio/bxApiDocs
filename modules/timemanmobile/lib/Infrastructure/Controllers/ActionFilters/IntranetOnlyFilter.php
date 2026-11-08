<?php

declare(strict_types=1);

namespace Bitrix\TimemanMobile\Infrastructure\Controllers\ActionFilters;

use Bitrix\Intranet\Util;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

final class IntranetOnlyFilter extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!Loader::includeModule('intranet') || !Util::isIntranetUser())
		{
			$this->addError(new Error('Access is restricted to intranet users', 403));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
