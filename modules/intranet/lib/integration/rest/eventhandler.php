<?php

namespace Bitrix\Intranet\Integration\Rest;

use Bitrix\Intranet\Internal\Integration\Rest\EmbeddingMenuProvider;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Config\Option;

class EventHandler
{
	/**
	 * Event adds new item to menu.
	 * @param Event $event
	 * @return EventResult
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 * @throws \Bitrix\Main\LoaderException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function onRegisterPlacementLeftMenu(Event $event): EventResult
	{
		$id = (int)$event->getParameter('ID');
		$embeddingMenuProvider = new EmbeddingMenuProvider();

		if ($id > 0 && $embeddingMenuProvider->isAvailable())
		{
			return static::reloadPlacementLeftMenu($embeddingMenuProvider);
		}

		return new EventResult(EventResult::ERROR);
	}

	/**
	 * Event deletes new item to menu.
	 * @param Event $event
	 * @return EventResult
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function onUnRegisterPlacementLeftMenu(Event $event): EventResult
	{
		$id = (int)$event->getParameter('ID');
		$embeddingMenuProvider = new EmbeddingMenuProvider();

		if ($id > 0 && $embeddingMenuProvider->isAvailable())
		{
			return static::reloadPlacementLeftMenu($embeddingMenuProvider);
		}

		return new EventResult(EventResult::ERROR);
	}

	private static function reloadPlacementLeftMenu(EmbeddingMenuProvider $embeddingMenuProvider)
	{
		$siteId = defined('SITE_ID') ? SITE_ID : static::getDefaultSiteId();

		$menuList = [];
		$placementList = [];
		$option = Option::get('intranet', 'left_menu_items_marketplace_' . $siteId);
		if (!empty($option))
		{
			$menuList = unserialize($option, ['allowed_classes' => false]);
			foreach ($menuList as $key => $item)
			{
				if ((int)($item['PLACEMENT_ID'] ?? 0) > 0)
				{
					$placementList[$item['PLACEMENT_ID']] = $key;
				}
			}
		}

		$needSave = false;
		foreach ($embeddingMenuProvider->getLeftMenuItems(\CIntranetRestService::LEFT_MENU) as $placement)
		{
			if (isset($placementList[$placement->id]))
			{
				unset($placementList[$placement->id]);
				continue;
			}
			if ($placement->leftMenuTitle === '')
			{
				continue;
			}

			$menuList[] = [
				'ID' => crc32($placement->url),
				'LINK' => $placement->url,
				'TEXT' => $placement->leftMenuTitle,
				'ADDITIONAL_LINKS' => [
					$placement->url,
				],
				'PLACEMENT_ID' => $placement->id,
			];
			$needSave = true;
		}

		foreach ($placementList as $placementId => $key)
		{
			unset($menuList[$key]);
			$needSave = true;
		}

		if ($needSave)
		{
			Option::set(
				'intranet',
				'left_menu_items_marketplace_' . $siteId,
				serialize($menuList),
				$siteId
			);

			if (defined('BX_COMP_MANAGED_CACHE'))
			{
				global $CACHE_MANAGER;
				$CACHE_MANAGER->ClearByTag('bitrix24_left_menu');
			}

			return new EventResult(EventResult::SUCCESS);
		}

		return new EventResult(EventResult::ERROR);
	}

	private static function getDefaultSiteId(): string
	{
		$defaultSiteId = \CSite::GetDefSite();

		return is_string($defaultSiteId) && $defaultSiteId !== '' ? $defaultSiteId : 's1';
	}
}
