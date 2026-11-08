<?php

declare(strict_types=1);

namespace Bitrix\Rest\Infrastructure\Controller\ActionFilter;

use Bitrix\Main;
use Bitrix\Main\Engine;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class AdminUser extends Engine\ActionFilter\Base
{

	public function onBeforeAction(Event $event)
	{
		if (!\CRestUtil::isAdmin())
		{
			$this->addError(new Main\Error(Loc::getMessage('REST_INFRASTRUCTURE_CONTROLLER_ACTION_FILTER_ADMIN_USER')));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
