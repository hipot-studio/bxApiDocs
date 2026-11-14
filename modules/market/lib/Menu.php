<?php

namespace Bitrix\Market;

use Bitrix\Main\Localization\Loc;
use Bitrix\Market\Infrastructure\Menu\MenuApplicationAdapter;
use Bitrix\Rest\Marketplace\Client;
use CRestUtil;

class Menu
{
	private const PARAM_DATASET = 'DATASET';
	private const PARAM_LOAD_CONTENT = 'LOAD_CONTENT';
	private const PARAM_IGNORE_AUTOBINDING = 'IGNORE_AUTOBINDING';

	public static function getList(): array
	{
		$menu = [];

		if (CRestUtil::isAdmin()) {
			$menu[] = Menu::getItem(
				Loc::getMessage('MARKET_MENU_INSTALLED_APPS'),
				SITE_DIR . 'market/installed/',
				[
					Menu::PARAM_DATASET => [
						Menu::PARAM_LOAD_CONTENT => 'list',
						Menu::PARAM_IGNORE_AUTOBINDING => 'true',
					],
					'INSTALLED_LIST' => 'Y',
				]
			);

			$numUpdates = Client::getAvailableUpdateNum();
			if ($numUpdates > 0) {
				$menu[] = Menu::getItem(
					Loc::getMessage('MARKET_MENU_REQUIRE_UPDATE', ['#NUM_UPDATES#' => $numUpdates]),
					SITE_DIR . 'market/installed/?updates=Y',
					[
						Menu::PARAM_DATASET => [
							Menu::PARAM_LOAD_CONTENT => 'list',
							Menu::PARAM_IGNORE_AUTOBINDING => 'true',
						],
						'NEED_UPDATE_LIST' => 'Y',
					]
				);
			}
		}

		$menu[] = Menu::getItem(
			Loc::getMessage('MARKET_MENU_MY_REVIEWS'),
			SITE_DIR . 'market/reviews/',
		);

		$apps = Menu::getInstalledApps();
		if (!empty($apps)) {
			$menu[] = Menu::getItem(
				'',
				'',
				[
					'DELIMITER' => 'Y',
				],
			);

			foreach ($apps as $app) {
				$menu[] = $app;
			}
		}

		return $menu;
	}

	private static function getItem(string $name, string $path, array $params = []): array
	{
		return [
			'NAME' => $name,
			'PATH' => $path,
			'PARAMS' => $params,
		];
	}

	public static function getInstalledApps(int $filterAppId = 0): array
	{
		global $USER;

		return (new MenuApplicationAdapter())->getInstalledAppsMenuItems(
			(int)$USER->GetID(),
			$filterAppId,
		);
	}
}
