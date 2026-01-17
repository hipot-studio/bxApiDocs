<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Integration\Bizproc\Menu;

use Bitrix\Main\Config\Option;
use Bitrix\Main\UserTable;

/**
 * Class for changing the order of menu items.
 * Moves the 'menu_ai_agents' item before 'menu_automation'.
 */
class MenuConverter
{
	private const OPTION_NAME = 'menu_ai_agents_reordered';
	private const OLD_MENU_OPTION_NAME = 'left_menu_sorted_items_old_ai_agents_s1';
	private const MENU_ITEM_TO_MOVE = 'menu_ai_agents';
	private const REFERENCE_MENU_ITEM = 'menu_automation';


	/**
	 * Applies menu changes for a specific user.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function convertForUser(int $userId): bool
	{
		$menu = \CUserOptions::GetOption('intranet', 'left_menu_sorted_items_s1', null, $userId);

		if ($menu && $this->shouldReorderAiAgents($menu))
		{
			\CUserOptions::SetOption('intranet', self::OLD_MENU_OPTION_NAME, $menu, false, $userId);
			$menu = $this->reorderAiAgents($menu);
			\CUserOptions::SetOption('intranet', 'left_menu_sorted_items_s1', $menu, false, $userId);

			Option::set('intranet', self::OPTION_NAME, 'Y');

			\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache($userId);

			return true;
		}

		return false;
	}

	/**
	 * Reverts menu changes for a specific user.
	 *
	 * @param int $userId
	 * @return bool
	 */
	public function revertForUser(int $userId): bool
	{
		$oldMenu = \CUserOptions::GetOption('intranet', self::OLD_MENU_OPTION_NAME, null, $userId);

		if (!$oldMenu)
		{
			return false;
		}

		\CUserOptions::SetOption('intranet', 'left_menu_sorted_items_s1', $oldMenu, false, $userId);
		\CUserOptions::DeleteOption('intranet', self::OLD_MENU_OPTION_NAME, false, $userId);
		Option::set('intranet', self::OPTION_NAME, 'N');
		\Bitrix\Intranet\Composite\CacheProvider::deleteUserCache($userId);

		return true;
	}

	/**
	 * Checks if the menu item order needs to be changed.
	 *
	 * @param array $menu
	 * @return bool
	 */
	private function shouldReorderAiAgents(array $menu): bool
	{
		$menuItems = [];
		$showedMenuItems = $menu['show'] ?? [];

		array_walk_recursive($showedMenuItems, static function ($item) use (&$menuItems)
		{
			if (is_string($item))
			{
				$menuItems[] = $item;
			}
		});

		$aiAgentPos = array_search(self::MENU_ITEM_TO_MOVE, $menuItems, true);
		$automationPos = array_search(self::REFERENCE_MENU_ITEM, $menuItems, true);

		$menuHasAiAgent = !is_bool($aiAgentPos);
		$automatizationNotInMenu = is_bool($automationPos);

		if ($automatizationNotInMenu)
		{
			return false;
		}

		if (!$menuHasAiAgent)
		{
			return true;
		}

		return $aiAgentPos !== $automationPos - 1;
	}

	/**
	 * Performs reordering of menu items.
	 *
	 * @param array $menu
	 * @return array
	 */
	private function reorderAiAgents(array $menu): array
	{
		$menu = $this->findAndRemoveMenuItem($menu, self::MENU_ITEM_TO_MOVE);

		return $this->insertMenuItemBefore($menu, self::MENU_ITEM_TO_MOVE, self::REFERENCE_MENU_ITEM);
	}

	/**
	 * Recursively finds and removes a menu item from the structure.
	 *
	 * @param array $menu
	 * @param string $menuItem
	 * @return array
	 */
	private function findAndRemoveMenuItem(array $menu, string $menuItem): array
	{
		$findAndRemove = static function (array &$array) use (&$findAndRemove, $menuItem)
		{
			foreach ($array as $key => &$value)
			{
				if ($value === $menuItem)
				{
					unset($array[$key]);

					if (is_int($key))
					{
						$keys = array_keys($array);
						if (empty($keys) || $keys === range(0, count($keys) - 1))
						{
							$array = array_values($array);
						}
					}

					return true;
				}

				if (is_array($value) && $findAndRemove($value))
				{
					if (empty($value))
					{
						unset($array[$key]);
					}
					return true;
				}
			}

			return false;
		};

		$findAndRemove($menu);

		return $menu;
	}

	/**
	 * Recursively inserts one menu item before another while preserving structure.
	 *
	 * @param array $menu
	 * @param string $itemToInsert
	 * @param string $referenceItem
	 * @return array
	 */
	private function insertMenuItemBefore(array $menu, string $itemToInsert, string $referenceItem): array
	{
		$inserted = false;
		$insert = static function (array &$array) use (&$insert, $itemToInsert, $referenceItem, &$inserted)
		{
			if (in_array($referenceItem, $array, true))
			{
				$newArray = [];
				foreach ($array as $key => $value)
				{
					if ($value === $referenceItem)
					{
						$newArray[] = $itemToInsert;
					}

					if (is_int($key))
					{
						$newArray[] = $value;
						continue;
					}

					$newArray[$key] = $value;
				}

				$array = $newArray;
				$inserted = true;

				return true;
			}

			foreach ($array as &$value)
			{
				if ($inserted)
				{
					return true;
				}
				if (is_array($value))
				{
					if ($insert($value))
					{
						return true;
					}
				}
			}

			return false;
		};

		if (isset($menu['show']) && is_array($menu['show']))
		{
			$insert($menu['show']);
		}

		return $menu;
	}
}
