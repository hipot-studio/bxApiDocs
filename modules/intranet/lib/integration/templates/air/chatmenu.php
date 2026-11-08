<?php

namespace Bitrix\Intranet\Integration\Templates\Air;

use Bitrix\Im\V2\Application\Navigation\Menu;
use Bitrix\Main\Engine\CurrentUser;

class ChatMenu
{
	/**
	 * Items built during the current hit: the header, the chat bar and the router share one build.
	 * @var array{menu: Menu, userId: int, items: array}|null
	 */
	private static ?array $hitCache = null;

	public static function getMenuItems(): array
	{
		if(!\Bitrix\Main\Loader::includeModule('im'))
		{
			return [];
		}

		$menu = Menu::getInstance();
		$userId = (int)CurrentUser::get()->getId();
		// The menu instance is a part of the key, so a replaced messenger service is never served stale items
		if (
			self::$hitCache !== null
			&& self::$hitCache['menu'] === $menu
			&& self::$hitCache['userId'] === $userId
		)
		{
			return self::$hitCache['items'];
		}

		$items = self::convertItems($menu->getMenuItems());
		self::$hitCache = [
			'menu' => $menu,
			'userId' => $userId,
			'items' => $items,
		];

		return $items;
	}

	public static function getMenuItemById(string $id): ?array
	{
		foreach (self::getMenuItems() as $item)
		{
			if ($item['ID'] === $id)
			{
				return $item;
			}
		}

		return null;
	}

	private static function convertItems(array $items): array
	{
		return array_map(function($item) {
			$eventName = 'BX.Intranet.Bitrix24.ChatMenu:onSelect';
			$data = ['id' => $item['id']];
			if (!empty($item['entityId']))
			{
				$data['entityId'] = $item['entityId'];
				if ($item['id'] === 'market')
				{
					$item['id'] = $item['id'] . '_' . $item['entityId'];
				}
			}

			$jsData = "const data = " . json_encode($data) . "; data.event = event;";

			return [
				'ID' => $item['id'],
				'TEXT' => $item['text'],
				'COUNTER_ID' => $item['id'],
				'ON_CLICK' => $jsData . "BX.Event.EventEmitter.emit('" . $eventName . "', data)",
			];

		}, $items);
	}
}
