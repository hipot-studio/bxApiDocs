<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\MainTool;

use Bitrix\Intranet\Settings\Tools\TeamWork;
use Bitrix\Main\ModuleManager;

class TeamWorkRouteProvider extends TeamWork
{
	/** @param list<string> $menuItemIds */
	public function __construct(private array $menuItemIds)
	{
	}

	public function getSortedSubgroupsIds(): array
	{
		$subgroups = $this->getSubgroupsIds();
		$ordered = [];
		foreach ($this->menuItemIds as $menuItemId)
		{
			$id = array_search($menuItemId, $subgroups, true);
			if ($id !== false)
			{
				$ordered[$id] = $menuItemId;
			}
		}

		return $ordered + $subgroups;
	}

	public function getSubgroups(): array
	{
		$subgroups = parent::getSubgroups();
		foreach ($subgroups as $id => $subgroup)
		{
			$moduleId = match ($id)
			{
				'instant_messenger', 'copilot' => 'im',
				'news', 'workgroups', 'collab' => 'socialnetwork',
				'docs', 'boards', 'disk' => 'disk',
				'calendar' => 'calendar',
				'mail' => 'mail',
				default => null,
			};
			if (
				($moduleId !== null && !ModuleManager::isModuleInstalled($moduleId))
				|| (
					in_array($id, ['instant_messenger', 'copilot', 'collab'], true)
					&& (
						!ModuleManager::isModuleInstalled('im')
						|| !\CBXFeatures::IsFeatureEnabled('WebMessenger')
					)
				)
			)
			{
				unset($subgroups[$id]);
			}
		}

		return $subgroups;
	}

	public function getRoute(bool $forAll): ?string
	{
		if (!$this->isEnabled())
		{
			return null;
		}

		foreach ($this->getSubgroups() as $id => $subgroup)
		{
			if ($subgroup['enabled'] && $subgroup['settings_path'])
			{
				return $forAll ? $this->getSubgroupSettingsPath()[$id] : $this->getLeftMenuPath();
			}
		}

		return null;
	}
}
