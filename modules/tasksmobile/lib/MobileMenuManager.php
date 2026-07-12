<?php

namespace Bitrix\TasksMobile;

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Mobile\Context;
use Bitrix\Mobile\Menu\Manager\MobileMenuManager as BaseMobileMenuManager;
use Bitrix\Mobile\Tab\Manager;
use Bitrix\Mobile\Menu\MenuList;
use Bitrix\Mobile\Menu\Analytics;
use Bitrix\Socialnetwork\Helper\Feature;

class MobileMenuManager extends BaseMobileMenuManager
{
	public static function onMobileMenuStructureBuilt(array $menu, $context): array
	{
		if (($context instanceof Context) && Loader::includeModule('intranet'))
		{
			$manager = new Manager();
			$active = array_keys($manager->getActiveTabs());

			$items = [];
			if (\Bitrix\TasksMobile\Settings::getInstance()->isTaskFlowAvailable())
			{
				$items[] = self::prepareFlowItem();
			}

			if (
				!in_array('projects', $active, true)
				&& Loader::includeModule('socialnetwork')
				&& \Bitrix\Intranet\Settings\Tools\ToolsManager::getInstance()->checkAvailabilityByToolId('projects')
			)
			{
				$items[] = self::prepareProjectsItem();
			}

			return self::addMenuItems($menu, MenuList::SECTION_TASKS, $items);
		}

		return $menu;
	}

	private static function prepareProjectsItem(): array
	{
		$isEnabledByFeature = Feature::isFeatureEnabled(Feature::PROJECTS_GROUPS)
			|| Feature::canTurnOnTrial(Feature::PROJECTS_GROUPS)
		;

		return [
			'id' => 'projects',
			'sort' => 200,
			'title' => Loc::getMessage('MENU_TASKS_SECTION_PROJECTS'),
			'imageName' => $isEnabledByFeature ? 'kanban' : 'lock',
			'counter' => 'projects',
			'path' => '/projects/',
			'params' => [
				'title' => Loc::getMessage('MENU_TASKS_SECTION_PROJECTS'),
				'analytics' => $isEnabledByFeature ? Analytics::projects() : null,
			],
		];
	}

	private static function prepareFlowItem(): array
	{
		return [
			'id' => 'flow',
			'sort' => 300,
			'title' => Loc::getMessage('MENU_TASKS_SECTION_FLOW'),
			'imageName' => 'bottleneck',
			'counter' => 'flow',
			'path' => '/flow/',
			'params' => [
				'title' => Loc::getMessage('MENU_TASKS_SECTION_FLOW'),
				'analytics' => Analytics::flows(),
			],
		];
	}
}
