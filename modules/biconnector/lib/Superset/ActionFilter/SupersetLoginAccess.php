<?php

namespace Bitrix\BIConnector\Superset\ActionFilter;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;

/**
 * Guards actions that return a Superset login link. The link signs the user into the Superset UI, so only users
 * who may edit reports get it: report readers work with data through act-as tools and do not enter the Superset UI.
 */
class SupersetLoginAccess extends Base
{
	public const ERROR_ACCESS_DENIED = 'SUPERSET_LOGIN_ACCESS_DENIED';

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_DASHBOARD_EDIT))
		{
			$this->addError(new UserFacingError(
				Loc::getMessage('BIC_ACTION_FILTER_SUPERSET_LOGIN_ACCESS_DENIED'),
				self::ERROR_ACCESS_DENIED,
			));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
