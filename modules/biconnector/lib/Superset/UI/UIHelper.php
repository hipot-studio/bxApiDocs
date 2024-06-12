<?php

namespace Bitrix\BIConnector\Superset\UI;

use Bitrix\Bitrix24;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;

class UIHelper
{
	public static function getOpenMarketScript(string $marketUrl, string $analyticSource = 'unknown'): string
	{
		Extension::load([
			'sidepanel',
			'biconnector.apache-superset-analytics',
		]);

		$marketUrl = \CUtil::JSEscape($marketUrl);
		$analyticSource = \CUtil::JSEscape($analyticSource);

		return <<<JS
				top.BX.SidePanel.Instance.open('{$marketUrl}', { customLeftBoundary: 0 });
				BX.BIConnector.ApacheSupersetAnalytics.sendAnalytics('market', 'market_call', {
					c_element: '{$analyticSource}'
				});
		JS;
	}

	public static function needShowDeleteInstanceButton(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		if (!Bitrix24\CurrentUser::get()->isAdmin())
		{
			return false;
		}

		if (!Bitrix24\Feature::isFeatureEnabled('bi_constructor'))
		{
			return true;
		}

		$lockNotice = (int)Option::get('bitrix24', '~license_lock_notice', 0);

		return
			$lockNotice > 0
			&& time() > $lockNotice
		;
	}
}
