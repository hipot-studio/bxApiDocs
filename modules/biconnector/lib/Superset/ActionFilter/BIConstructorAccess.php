<?php

namespace Bitrix\BIConnector\Superset\ActionFilter;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Superset\Error\UserFacingError;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class BIConstructorAccess extends Base
{
	// Consumed by the biconnectormobile dashboard-list screen (detectNoAccessCode) to pick the "no access" screen text.
	public const ERROR_INSUFFICIENT_TARIFF = 'INSUFFICIENT_TARIFF';
	public const ERROR_TOOL_DISABLED = 'TOOL_DISABLED';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	public function onBeforeAction(Event $event): ?EventResult
	{
		if (!Feature::isBuilderEnabled())
		{
			$this->addError(new UserFacingError(Loc::getMessage('BIC_ACTION_FILTER_FEATURE_UNAVAILABLE'), self::ERROR_INSUFFICIENT_TARIFF));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (Loader::includeModule('intranet') && !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi'))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BIC_ACTION_FILTER_TOOL_DISABLED'), self::ERROR_TOOL_DISABLED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			$this->addError(new UserFacingError(Loc::getMessage('BIC_ACTION_FILTER_ACCESS_DENIED'), self::ERROR_ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}
}
