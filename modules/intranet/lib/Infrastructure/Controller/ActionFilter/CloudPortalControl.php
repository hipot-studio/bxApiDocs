<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

class CloudPortalControl extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!Loader::includeModule('bitrix24'))
		{
			$this->addError(new Error('Module bitrix24 is required', 'BITRIX24_MODULE_REQUIRED'));

			return new EventResult(EventResult::ERROR, null, 'intranet', $this);
		}

		return null;
	}
}