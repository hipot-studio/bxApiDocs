<?php

namespace Bitrix\Crm\Service\Timeline\Layout\Menu;

use Bitrix\Crm\Service\Timeline\Layout\Action;
use Bitrix\Crm\Service\Timeline\Layout\Menu;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;
use Bitrix\Ui\Public\Enum\IconSet\Main as MainIcon;
use Bitrix\Ui\Public\Enum\IconSet\Outline;

class MenuItemFactory
{
	public static function createEditMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_EDIT')))
			->setHideIfReadonly()
			->setSort(9900)
			->setIcon(MainIcon::EDIT_PENCIL)
		;
	}

	public static function createViewMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_VIEW')))
			->setSort(9900)
			->setIcon(MainIcon::OPENED_EYE)
		;
	}

	public static function createAddFileMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_ADD_FILE')))
			->setHideIfReadonly()
			->setSort(9990)
			->setIcon(Outline::UPLOAD_FILE)
		;
	}

	public static function createChangeResponsibleMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_CHANGE_RESPONSIBLE')))
			->setHideIfReadonly()
			->setSort(9991)
			->setIcon(Outline::DELEGATE)
		;
	}

	public static function createDeleteTagMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_DELETE_TAG')))
			->setHideIfReadonly()
			->setSort(9992)
			->setIcon(Outline::CIRCLE_CROSS)
		;
	}

	public static function createRepeatMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_REPEAT')))
			->setHideIfReadonly()
			->setSort(9991)
		;
	}

	public static function createDownloadFileMenuItem(string $filename = null): MenuItem
	{
		$title = (string)Loc::getMessage('CRM_TIMELINE_MENU_DOWNLOAD_FILE');
		if (isset($filename))
		{
			$title = sprintf('%s "%s"', $title, $filename);
		}

		return (new MenuItem($title))
			->setHideIfReadonly()
			->setSort(9995)
			->setIcon(Outline::DOWNLOAD)
		;
	}

	public static function createFilterRelatedMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_FILTER_RELATED')))
			->setSort(9997)
			->setIcon(Outline::FILTER)
		;
	}

	public static function createMoveToMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_MOVE_TO')))
			->setHideIfReadonly()
			->setSort(9998)
			->setIcon(Outline::MOVE_TO)
		;
	}

	public static function createDeleteMenuItem(): MenuItem
	{
		return (new MenuItem(Loc::getMessage('CRM_TIMELINE_MENU_DELETE')))
			->setHideIfReadonly()
			->setSort(9999)
			->setDesign(MenuItemDesign::ALERT)
			->setIcon(Outline::TRASHCAN)
		;
	}

	public static function createFromArray(array $menuItem): MenuItem
	{
		$title = $menuItem['title'] ?? $menuItem['text'] ?? '';

		if (isset($menuItem['delimiter']) && $menuItem['delimiter'])
		{
			return self::applyCommonFields(
				new MenuItemDelimiter($title),
				$menuItem,
			);
		}

		$menuItemItems = $menuItem['items'] ?? null;
		if (is_array($menuItemItems))
		{
			return self::applyCommonFields(
				new MenuItemSubmenu(
					$title,
					self::createMenuFromArray($menuItem),
				),
				$menuItem,
			);
		}

		return self::applyCommonFields(
			(new MenuItem($title))
				->setAction(self::createMenuItemAction($menuItem)),
			$menuItem,
		);
	}

	private static function applyCommonFields(MenuItem $item, array $menuItem): MenuItem
	{
		if (isset($menuItem['subtitle']))
		{
			$item->setSubtitle($menuItem['subtitle']);
		}
		if (isset($menuItem['design']))
		{
			$item->setDesign($menuItem['design']);
		}
		if (isset($menuItem['isSelected']))
		{
			$item->setIsSelected((bool)$menuItem['isSelected']);
		}
		if (isset($menuItem['isLocked']))
		{
			$item->setIsLocked((bool)$menuItem['isLocked']);
		}
		if (isset($menuItem['badgeText']) && is_array($menuItem['badgeText']))
		{
			$item->setBadgeText(new BadgeText(
				(string)($menuItem['badgeText']['title'] ?? ''),
				$menuItem['badgeText']['color'] ?? null,
			));
		}
		if (isset($menuItem['sectionCode']))
		{
			$item->setSectionCode($menuItem['sectionCode']);
		}

		return $item;
	}

	private static function createMenuFromArray(array $menu): Menu
	{
		$menuObj = new Menu();

		$items = $menu['items'] ?? $menu;
		$index = 0;
		foreach ($items as $key => $item)
		{
			if (!is_array($item))
			{
				continue;
			}
			$id = $item['id'] ?? (is_string($key) ? $key : "submenu_$index");
			$index++;
			$menuObj->addItem($id, self::createFromArray($item));
		}

		if (!empty($menu['sections']) && is_array($menu['sections']))
		{
			$menuObj->setSections($menu['sections']);
		}

		return $menuObj;
	}

	private static function createMenuItemAction(array $menuItem): ?Action
	{
		if (isset($menuItem['href']))
		{
			return new Action\Redirect(new Uri($menuItem['href']));
		}
		if (isset($menuItem['onclick']))
		{
			return new Action\JsCode($menuItem['onclick']);
		}

		return null;
	}
}
