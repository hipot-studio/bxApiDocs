<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Controller\ActionFilter;

use Bitrix\Main;
use Bitrix\Intranet;
use Bitrix\Main\Engine;
use Bitrix\Main\Event;

class IntranetUser extends Engine\ActionFilter\Base
{
	public function onBeforeAction(Event $event)
	{
		if (Main\Loader::includeModule('intranet'))
		{
			$filter = new Intranet\ActionFilter\IntranetUser();
			return $filter->onBeforeAction($event);
		}

		return null;
	}
}
