<?php

namespace Bitrix\Intranet\Settings\Tools;

use Bitrix\Intranet\Internal\MainTool\TeamWorkRouteProvider;
use Bitrix\Intranet\Portal\FirstPage;
use Bitrix\Intranet\UI\LeftMenu\Preset\Manager;
use Bitrix\Intranet\UI\LeftMenu\Preset\PresetAbstract;
use Bitrix\Intranet\UI\LeftMenu\Preset\PresetInterface;
use Bitrix\Main\Config\Option;

class FirstPageChanger
{
	private FirstPage $firstPage;

	public function __construct(private ToolsManager $toolsManager)
	{
		$this->firstPage = FirstPage::getInstance();
	}

	public function changeForAllUsers(string $siteId = SITE_ID): void
	{
		$effective = Manager::getEffectiveState($siteId, 0);
		$presetId = $effective !== null
			? ($effective['presetId'] ?? 'social')
			: Option::get('intranet', 'left_menu_preset', '', $siteId)
		;
		$preset = Manager::getPreset($presetId, $siteId);
		$structure = [];

		if ($preset instanceof PresetAbstract)
		{
			$structure = $preset->getToolsStructure($effective !== null) ?? $preset->getStructure();
		}

		$firstPage = $this->getAvailableFirstPage($structure, $siteId, $effective !== null ? $preset : null);

		if ($firstPage)
		{
			Option::set('intranet', 'left_menu_first_page', $firstPage, $siteId);
			Manager::clearCache($siteId);
			if (\CUserOptions::DeleteOptionsByName('intranet', 'left_menu_first_page_changed_' . $siteId))
			{
				Manager::clearCache($siteId);
			}
		}

		$this->firstPage->clearCacheForAll();
	}

	public function changeForCurrentUser($structure): void
	{
		$effective = Manager::getEffectiveState(SITE_ID);
		if ($effective !== null && $effective['source'] === 'forced')
		{
			return;
		}

		$firstPage = '';

		foreach ($structure as $menuItem)
		{
			if ($menuItem['ID'] && $this->toolsManager->checkAvailabilityByMenuId($menuItem['ID']))
			{
				if (isset($menuItem['PARAMS']['real_link']) && is_string($menuItem['PARAMS']['real_link']))
				{
					$firstPage = $menuItem['PARAMS']['real_link'];

					break;
				}

				if (!$firstPage && isset($menuItem['LINK']) && is_string($menuItem['LINK']))
				{
					$firstPage = $menuItem['LINK'];

					break;
				}
			}
		}

		if ($firstPage)
		{
			if (\CUserOptions::SetOption('intranet', 'left_menu_first_page_' . SITE_ID, $firstPage))
			{
				Manager::clearCache(SITE_ID);
			}
			if (\CUserOptions::SetOption('intranet', 'left_menu_first_page_changed_' . SITE_ID, 'Y'))
			{
				Manager::clearCache(SITE_ID);
			}
		}

		$this->firstPage->clearCache();
	}

	public function checkNeedChanges(?int $userId = null): bool
	{
		$userId ??= (int)\Bitrix\Main\Engine\CurrentUser::get()->getId();
		$effective = Manager::getEffectiveState(SITE_ID, $userId);
		if ($effective !== null && $effective['source'] === 'forced')
		{
			return false;
		}

		$isFirstPageChanged = \CUserOptions::GetOption(
			'intranet',
			'left_menu_first_page_changed_' . SITE_ID,
			'N',
			$userId,
		) === 'Y';
		if ($effective !== null)
		{
			return !$isFirstPageChanged
				&& (
					$effective['sortData'] !== null
					|| (
						$effective['hasPersonalChoice']
						&& $effective['presetId'] !== $effective['commonPresetId']
					)
				)
			;
		}

		$userSortedItems = \CUserOptions::GetOption(
			'intranet',
			'left_menu_sorted_items_' . SITE_ID,
			'N',
			$userId,
		) !== 'N';
		$userPreset = \CUserOptions::GetOption('intranet', 'left_menu_preset_' . SITE_ID, '', $userId);
		$sitePreset = Option::get('intranet', 'left_menu_preset', '', SITE_ID);

		return !$isFirstPageChanged && ($userSortedItems || ($userPreset && $userPreset !== $sitePreset));
	}

	private function getAvailableFirstPage(
		array $structure,
		string $siteId = SITE_ID,
		?PresetInterface $commonPreset = null,
	): ?string
	{
		$menuId = is_array($structure['shown'] ?? null)
			? $this->getAvailableMenuIdFromStructure($structure['shown'])
			: null
		;
		$group = null;
		if ($commonPreset !== null && $menuId !== null)
		{
			$group = $structure['shown'][$menuId] ?? [];
			$group = is_array($group) ? $group : [];
		}
		$availableUrl = $menuId ? $this->getUrlByMenuId($menuId, $group) : null;

		if (!$availableUrl)
		{
			$menuUser = new \Bitrix\Intranet\UI\LeftMenu\User();

			$preset = $commonPreset ?? Manager::getPreset(siteId: $siteId);
			foreach ($preset->getItems() as $item)
			{
				$customItem = $item->prepareData($menuUser);

				if (
					isset($customItem['ID'])
					&& is_string($customItem['LINK'])
					&& $this->toolsManager->checkAvailabilityByMenuId($customItem['ID'])
				)
				{
					$availableUrl = $customItem['LINK'];

					break;
				}
			}
		}

		return $availableUrl;
	}

	private function getUrlByMenuId(string $menuItemId, ?array $group = null): ?string
	{
		$tools = $this->toolsManager->getToolList();

		foreach ($tools as $id => $tool)
		{
			if ($id === $menuItemId || $tool->getMenuItemId() === $menuItemId || in_array($menuItemId, $tool->getAdditionalMenuItemIds()))
			{
				if ($tool instanceof TeamWork && $group !== null)
				{
					return (new TeamWorkRouteProvider($group))->getRoute(true) ?? '/stream/';
				}

				return $tool->getLeftMenuPath();
			}

			$subgroupIds = $tool->getSubgroupsIds();
			$subgroupPaths = $tool->getSubgroupSettingsPath();

			foreach ($subgroupIds as $subgroupId => $subgroupMenuId)
			{
				if ($subgroupMenuId === $menuItemId)
				{
					return $subgroupPaths[$subgroupId];
				}
			}
		}

		return null;
	}

	private function getAvailableMenuIdFromStructure(array $structure): ?string
	{
		foreach ($structure as $key => $menuItem)
		{
			if (is_array($menuItem))
			{
				if (is_string($key) && $this->toolsManager->checkAvailabilityByMenuId($key))
				{
					return $key;
				}
			}
			elseif (is_string($menuItem) && $this->toolsManager->checkAvailabilityByMenuId($menuItem))
			{
				return $menuItem;
			}
		}

		return null;
	}
}
