<?php

namespace Bitrix\ImOpenLines\V2\Controller\Filter;

use Bitrix\ImOpenLines\Integrations\UI\EntitySelector\CrmFormProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Engine\ActionFilter\Base;

class CrmFormAccessCheck extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$provider = new CrmFormProvider();
		if (!$provider->isAvailable())
		{
			$this->addError(new Error('Access denied'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
