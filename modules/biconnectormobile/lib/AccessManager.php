<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

final class AccessManager
{
	/**
	 * Checks whether the current user may see BI dashboards in the mobile app.
	 * Single source of the rule for every mobile entry point: the BI menu item and the CRM Analytics tab.
	 *
	 * @return bool
	 */
	public static function canViewDashboards(): bool
	{
		if (!Loader::includeModule('biconnector'))
		{
			return false;
		}

		// Tariff (Feature::isBuilderEnabled) is intentionally not checked here: a user on an insufficient
		// tariff still gets the entry point and an upsell screen on it. A disabled tool and missing rights hide it.
		if (Loader::includeModule('intranet') && !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi'))
		{
			return false;
		}

		return AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_ACCESS);
	}
}
