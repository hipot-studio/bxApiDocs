<?php

declare(strict_types=1);

namespace Bitrix\BIConnectorMobile\Public\Features;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Mobile\Config\FeatureFlag;

/**
 * Portal-wide switch of the mobile BI feature, off until the option is set by hand.
 * Rights, tool and tariff are none of its business: the visibility rules live in ScopeVisibilityService.
 */
final class MobileBIFeature extends FeatureFlag
{
	private const OPTION_NAME = 'bi_mobile_enabled';

	/**
	 * The cached menu list knows nothing about the flag, so the "Analytics" section keeps its previous state until
	 * the tag MenuListCache registers is cleared. Option events are no help here: Option::delete publishes none.
	 */
	private const MENU_LIST_CACHE_TAG = 'mobile_custom_menu';

	public function isEnabled(): bool
	{
		return Option::get('biconnectormobile', self::OPTION_NAME, 'N') === 'Y';
	}

	public function enable(): void
	{
		Option::set('biconnectormobile', self::OPTION_NAME, 'Y');
		$this->clearMenuListCache();
	}

	public function disable(): void
	{
		Option::delete('biconnectormobile', ['name' => self::OPTION_NAME]);
		$this->clearMenuListCache();
	}

	private function clearMenuListCache(): void
	{
		Application::getInstance()->getTaggedCache()->clearByTag(self::MENU_LIST_CACHE_TAG);
	}
}
