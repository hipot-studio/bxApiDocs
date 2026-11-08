<?php
namespace Bitrix\Intranet\UI\LeftMenu\Preset;

use Bitrix\Intranet\Internal\MainTool\AccessPolicy;
use Bitrix\Intranet\Settings\Tools;
use Bitrix\Intranet\UI\LeftMenu;
use Bitrix\Main;

Main\Localization\Loc::loadMessages(__FILE__);

abstract class PresetAbstract implements PresetInterface
{
	const CODE = 'undefined_or_abstract_does_not_matter';
	protected $siteId;
	private static array $cache = [];

/*	const STRUCTURE = [
		'shown' => [
			'menu_teamwork' => [
				'menu_files',
				'menu_documents',
				'menu_calendar',
				'menu_timeman_sect',
				'menu_all_groups',
			],
			'menu_marketplace_group' => [
				'menu_marketplace_sect'
			],
			'menu_tasks',
			'menu_im_messenger',
			'menu_rpa',
			'menu_crm_favorite',
			'menu_sites',
		],
		'hidden' => [

		]
	];
*/
	public function __construct(string $siteId)
	{
		$this->siteId = $siteId;
	}

	protected function getSiteId()
	{
		return $this->siteId;
	}

	public function getCode(): string
	{
		return static::CODE;
	}

	public static function clearCache(string $siteId): void
	{
		unset(self::$cache[$siteId]);
	}

	protected function &getCache(): array
	{
		$userId = (int)Main\Engine\CurrentUser::get()->getId();
		$featureEnabled = (int)AccessPolicy::isFeatureEnabled();
		self::$cache[$this->siteId][$userId][$featureEnabled][static::class] ??= [];

		return self::$cache[$this->siteId][$userId][$featureEnabled][static::class];
	}

	private function getPlainSort()
	{
		$cache = &$this->getCache();
		$plainArray = &$cache['plainSort'];

		if ($plainArray)
		{
			return $plainArray;
		}

		$flatten = function($value, $key) use (&$plainArray, &$flatten) {
			if (is_string($key))
			{
				$plainArray[] = $key;
			}
			if (is_array($value))
			{
				array_walk($value, $flatten);
			}
			else
			{
				$plainArray[] = strval($value);
			}
		};

		$structure = $this->getFinalStructure();
		array_walk($structure, $flatten);

		return $plainArray;
	}

	public function getSortForItem($itemId, $parentId): ?int
	{
		$res = array_search($itemId, $this->getPlainSort());
		return $res === false ? null : $res;
	}

	public final function getFinalStructure(): array
	{
		$cache = &$this->getCache();
		$finalStructure = &$cache['finalStructure'];
		if (!$finalStructure)
		{
			$finalStructure = $this->getSavedStructure() ?: $this->getToolsStructure() ?: $this->getStructure();
		}
		return $finalStructure;
	}

	private function getSavedStructure(): ?array
	{
		$cache = &$this->getCache();
		$savedStructure = &$cache['savedStructure'];
		if ($savedStructure)
		{
			return $savedStructure;
		}
		$effective = Manager::getEffectiveState($this->getSiteId());
		$data = $effective !== null
			? $effective['sortData']
			: \CUserOptions::GetOption("intranet", "left_menu_sorted_items_" . $this->getSiteId())
		;

		if (!$data)
		{
			return null;
		}
		if (!isset($data['version']))
		{
			$savedStructure = self::oldToNewStructure($data, $this->getStructure());
		}
		else
		{
			$flatOwnStructure = self::flatten(array_merge($data['show'], $data['hide']));
			$expectedStructure = $this->getStructure();
			$notNaturalSelection = function(&$group, $key) use (&$notNaturalSelection, $flatOwnStructure)
			{
				if (is_array($group))
				{
					$needToSelect = false;
					foreach ($group as $menuKey => $menuItem)
					{
						if (is_array($menuItem))
						{
							$needToSelect = true;
						}
						else if (array_key_exists($menuItem, $flatOwnStructure))
						{
							unset($group[$menuKey]);
						}
					}
					if ($needToSelect)
					{
						array_walk($group, $notNaturalSelection);
						foreach ($group as $menuKey => $menuItem)
						{
							if (empty($menuItem))
							{
								unset($group[$menuKey]);
							}
						}
					}
				}
			};
			array_walk($expectedStructure, $notNaturalSelection);
			$savedStructure = array_merge_recursive($data, ['show' => $expectedStructure['shown'], 'hide' => $expectedStructure['hidden']]);
			$savedStructure = ['shown' => $savedStructure['show'], 'hidden' => $savedStructure['hide']];
		}

		return $savedStructure;
	}

	public static function isForcedSortAllowed(array $data, array $structure): bool
	{
		if (!is_array($data['show'] ?? null) || !is_array($data['hide'] ?? null))
		{
			return false;
		}

		if (!isset($data['version']))
		{
			foreach (array_merge($data['show'], $data['hide']) as $item)
			{
				if (!is_string($item))
				{
					return false;
				}
			}
		}

		$candidate = isset($data['version'])
			? ['shown' => $data['show'], 'hidden' => $data['hide']]
			: self::oldToNewStructure($data, $structure)
		;
		$expected = $structure['shown'] ?? [];
		$shown = $candidate['shown'];
		if ($expected === [] || $shown === [])
		{
			return false;
		}

		$expectsVibe = reset($expected) === 'main_page';
		if ($expectsVibe)
		{
			unset($expected[array_key_first($expected)]);
			if ($expected === [] || reset($shown) !== 'main_page')
			{
				return false;
			}
		}

		$firstKey = array_key_first($shown);
		if ($shown[$firstKey] === 'main_page')
		{
			unset($shown[$firstKey]);
		}
		if ($shown === [])
		{
			return false;
		}

		$protectedIds = ['main_page'];
		while ($expected !== [])
		{
			$expectedKey = array_key_first($expected);
			$expectedItem = $expected[$expectedKey];
			$firstKey = array_key_first($shown);
			if (is_array($expectedItem))
			{
				if (
					$firstKey !== $expectedKey
					|| !is_array($shown[$firstKey])
					|| $expectedItem === []
					|| $shown[$firstKey] === []
				)
				{
					return false;
				}
				$protectedIds[] = $expectedKey;
				$expected = $expectedItem;
				$shown = $shown[$firstKey];
			}
			else
			{
				if (!is_string($expectedItem) || $shown[$firstKey] !== $expectedItem)
				{
					return false;
				}
				$protectedIds[] = $expectedItem;
				break;
			}
		}

		$counts = self::countStructureIds($candidate);
		foreach ($protectedIds as $itemId)
		{
			if ($itemId === 'main_page')
			{
				if (
					isset($counts[$itemId])
					&& ($counts[$itemId] !== 1 || reset($candidate['shown']) !== $itemId)
				)
				{
					return false;
				}
			}
			elseif (($counts[$itemId] ?? 0) !== 1)
			{
				return false;
			}
		}

		return true;
	}

	private static function countStructureIds(array $structure): array
	{
		$counts = [];
		foreach ($structure as $key => $value)
		{
			if (is_string($key))
			{
				$counts[$key] = ($counts[$key] ?? 0) + 1;
			}
			if (is_array($value))
			{
				foreach (self::countStructureIds($value) as $itemId => $count)
				{
					$counts[$itemId] = ($counts[$itemId] ?? 0) + $count;
				}
			}
			elseif (is_string($value))
			{
				$counts[$value] = ($counts[$value] ?? 0) + 1;
			}
		}

		return $counts;
	}

	public function getToolsStructure(bool $forAllUsers = false): ?array
	{
		if ($this instanceof Custom)
		{
			return null;
		}

		$effective = Manager::getEffectiveState($this->getSiteId(), $forAllUsers ? 0 : null);
		if (
			$effective !== null
				? ($effective['source'] === 'forced' || $effective['hasPersonalChoice'])
				: Manager::hasOwnPreset()
		)
		{
			return null;
		}

		$savedToolsSort = Tools\ToolsManager::getInstance()->getSorter()->getSavedSort();

		if ($savedToolsSort)
		{
			$savedToolsSort = array_values($savedToolsSort);
			$structure = $this->getStructure();
			$shownStructure = $structure['shown'];
			$structureSortedByTools['hidden'] = $structure['hidden'];
			$structureSortedByTools['shown'] = [];
			$flattenShownStructure = [];

			foreach ($shownStructure as $key => $value)
			{
				if (is_array($value))
				{
					$flattenShownStructure[] = $key;
				}
				else
				{
					$flattenShownStructure[] = $value;
				}
			}

			$indexes = [];
			$ids = [];

			foreach ($savedToolsSort as $id)
			{
				$index = array_search($id, $flattenShownStructure, true);

				if ($index !== false)
				{
					$indexes[] = $index;
					$ids[] = $id;
				}
			}

			sort($indexes);
			$structureSortToolsItem = array_combine($indexes, $ids);

			foreach ($structureSortToolsItem as $key => $value)
			{
				$flattenShownStructure[$key] = $value;
			}

			foreach ($flattenShownStructure as $key => $value)
			{
				$index = array_search($value, $shownStructure, true);

				if ($index !== false)
				{
					$structureSortedByTools['shown'][$key] = $shownStructure[$index];
				}
				elseif (isset($shownStructure[$value]))
				{
					$structureSortedByTools['shown'][$value] = $shownStructure[$value];
				}
			}

			return $structureSortedByTools;
		}

		return null;
	}

	public static function oldToNewStructure(array $oldData, array $newData): array
	{
		$data = [
			'shown' => isset($oldData['show']) && is_array($oldData['show']) ? $oldData['show'] : [],
			'hidden' => isset($oldData['hide']) && is_array($oldData['hide']) ? $oldData['hide'] : []
		];

		$result = ['shown' => [], 'hidden' => []];
		$systemGroups = [];
		$replaceItems = [
			'menu_live_feed' => 'menu_teamwork',
			'menu_marketplace_sect' => 'menu_marketplace_group'
		];
		foreach ($result as $visibility => $items)
		{
			foreach ($data[$visibility] as $itemId)
			{
				if (array_key_exists($itemId, $replaceItems))
				{
					$groupId = $replaceItems[$itemId];
					$expectedStructure = null;
					foreach ($newData as $structureContent)
					{
						if (is_array($structureContent) && array_key_exists($groupId , $structureContent))
						{
							$expectedStructure = $structureContent[$groupId];
							break;
						}
					}
					if ($expectedStructure !== null)
					{
						$result[$visibility][$groupId] = [$itemId];
						$systemGroups[$groupId] = [$visibility, $expectedStructure];
					}
					continue;
				}

				$result[$visibility][] = $itemId;
			}
		}

		foreach ($systemGroups as $groupId => [$visibility, $expectedStructure])
		{
			$menu = &$result[$visibility];
			$foundItems = array_filter(
				$menu,
				static fn (mixed $item): bool => is_string($item) && in_array($item, $expectedStructure, true),
			);
			$menu[$groupId] = array_merge(
				$menu[$groupId],
				array_values($foundItems)
			);
			$notFoundItems = array_filter(
				$expectedStructure,
				static fn (mixed $item): bool => is_array($item) || !in_array($item, $menu[$groupId], true),
			);
			if ($visibility === 'shown')
			{
				$notFoundItems = array_filter(
					$notFoundItems,
					static fn (mixed $item): bool => is_array($item) || !in_array($item, $result['hidden'], true),
				);
				$menu[$groupId] = array_merge(
					$menu[$groupId],
					$notFoundItems,
				);
			}
			$result[$visibility] = array_filter(
				$menu,
				static fn (mixed $item): bool => is_array($item) || !in_array($item, $menu[$groupId], true),
			);
			unset($menu);
		}
		return ['shown' => $result['shown'], 'hidden' => $result['hidden']];
	}

	private static function flatten(array $someArray): array
	{
		$plainStructure = [];
		$flatten = function($value, $key, $parent) use (&$plainStructure, &$flatten) {
			if (is_string($key))
			{
				$plainStructure[$key] = $parent;
				$parent = $key;
			}
			if (is_array($value))
			{
				array_walk($value, $flatten, $parent);
			}
			else
			{
				$plainStructure[strval($value)] = $parent;
			}
		};

		array_walk($someArray, $flatten, null);
		return $plainStructure;
	}

	private function getPlainStructure()
	{
		$cache = &$this->getCache();
		$plainStructure = &$cache['plainStructure'];

		if (!$plainStructure)
		{
			$plainStructure = self::flatten($this->getFinalStructure());
		}
		return $plainStructure;
	}

	public function getParentForItem($itemId, LeftMenu\MenuItem\Basic $item): ?string
	{
		$plainStructure = $this->getPlainStructure();
		if (array_key_exists($itemId, $plainStructure))
		{
			return $plainStructure[$itemId];
		}
		return null;
	}

	public function getItems(): array
	{
		$cache = &$this->getCache();
		$result = &$cache['items'];
		if ($result)
		{
			return $result;
		}

		$res = $this->getPlainStructure();
		$result = [];

		if (in_array('menu_teamwork', $res, true))
		{
			$menuTeamWork = new LeftMenu\MenuItem\GroupSystem([
				'ID' => 'menu_teamwork',
				'TEXT' => Main\Localization\Loc::getMessage('MENU_TEAMWORK')
			]);
			if (static::CODE === Social::CODE)
			{
				$menuTeamWork->setDefaultOptions(['collapsed_mode' => 'expanded']);
			}

			$result[] = $menuTeamWork;
		}

		if (in_array('menu_marketplace_group', $res))
		{
			$result[] = new LeftMenu\MenuItem\GroupSystem([
				'ID' => 'menu_marketplace_group',
				'TEXT' => Main\Localization\Loc::getMessage('MENU_APPLICATIONS'),
			], $this->getSiteId());
		}
		return $result;
	}
}
