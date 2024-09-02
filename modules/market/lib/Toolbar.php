<?php

namespace Bitrix\Market;

use Bitrix\Market\Subscription\Status;
use Bitrix\Rest\Marketplace\Client;
use CRestUtil;

class Toolbar
{
	public static function getInfo($marketAction, $searchAction): array
	{
		$result = [
			'CATEGORIES' => Categories::forceGet(),
			'FAV_NUMBERS' => count(AppFavoritesTable::getUserFavorites()),
			'MENU_INFO' => Menu::getList(),
			'MARKET_SLIDER' => Status::getSlider(),
			'MARKET_ACTION' => $marketAction,
			'SEARCH_ACTION' => $searchAction,
		];

		if (CRestUtil::isAdmin()) {
			$result['NUM_UPDATES'] = Client::getAvailableUpdateNum();
		}

		return $result;
	}
}