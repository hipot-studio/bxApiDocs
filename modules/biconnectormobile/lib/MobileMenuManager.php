<?php

namespace Bitrix\BIConnectorMobile;

use Bitrix\BIConnectorMobile\Service\ScopeVisibilityService;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Menu\Manager\MobileMenuManager as BaseMobileMenuManager;
use Bitrix\Mobile\Menu\MenuList;

class MobileMenuManager extends BaseMobileMenuManager
{
	public static function onMobileMenuStructureBuilt(array $menu, $context): array
	{
		// The root entry point lists dashboards of every scope, so a non-empty scope is not a condition of it.
		if (!ScopeVisibilityService::isRootEntryVisible())
		{
			return $menu;
		}

		return self::addMenuItem($menu, MenuList::SECTION_ANALYTICS, self::prepareBiItem());
	}

	private static function prepareBiItem(): array
	{
		$title = Loc::getMessage('BICONNECTORMOBILE_MENU_BI_TITLE');
		$titleJs = \CUtil::JSEscape($title);
		$listVersion = \Bitrix\MobileApp\Janative\Manager::getComponentVersion('biconnector:biconnector.dashboard.list');

		return [
			'id' => 'biconnectormobile_bi',
			'sort' => 450,
			'title' => $title,
			// Icon name from the Janative icon set (assets/icons): Icon.STATISTICS_ARROW.
			'imageName' => 'statistics_arrow',
			'params' => [
				'onclick' => <<<JS
					ComponentHelper.openLayout({
						name: "biconnector:biconnector.dashboard.list",
						version: "{$listVersion}",
						widgetParams: {
							titleParams: {
								text: "{$titleJs}",
								useLargeTitleMode: true
							}
						}
					});
				JS,
			],
		];
	}
}
