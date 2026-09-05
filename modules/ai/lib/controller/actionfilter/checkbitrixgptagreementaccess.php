<?php

declare(strict_types=1);

namespace Bitrix\AI\Controller\ActionFilter;

use Bitrix\AI\Facade\User;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use CBitrix24;

class CheckBitrixGptAgreementAccess extends Base
{
	public function onBeforeAction(Event $event): ?EventResult
	{
		$user = User::getInstance();

		$isAdmin =
			$user !== null
			&& (Loader::includeModule('bitrix24')
				? CBitrix24::IsPortalAdmin((int)$user->GetID())
				: $user->isAdmin());

		if (!$isAdmin)
		{
			$this->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

}
