<?php

namespace Bitrix\BIConnector\Superset\ActionFilter;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Localization\LocalizableMessageInterface;

class ExternalDashboardConfigAccess extends Base
{
	public function __construct(private readonly LocalizableMessageInterface $accessDeniedMessage)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			$message = $this->accessDeniedMessage->localize(Loc::getCurrentLang()) ?? '';
			$this->addError(new UserFacingError($message));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
