<?php

namespace Bitrix\Intranet\UI\LeftMenu\Preset;

use \Bitrix\Main;
use \Bitrix\Intranet\UI\LeftMenu;

class Custom extends PresetAbstract
{
	public const CODE = 'custom';

	public function getName(): string
	{
		return 'Custom';
	}

	public function getStructure(): array
	{
		$structure = unserialize(
			\COption::GetOptionString('intranet', 'left_menu_custom_preset_sort', ''),
			['allowed_classes' => false],
		);
		$structure = is_array($structure) ? [
			'shown' => isset($structure['show']) && is_array($structure['show']) ? $structure['show'] : [],
			'hidden' => isset($structure['hide']) && is_array($structure['hide']) ? $structure['hide'] : [],
		] : [];

		$baseStructure = (new Social($this->getSiteId()))->getStructure();
		$existingIds = $this->collectIds($structure);
		foreach (['shown', 'hidden'] as $section)
		{
			if (!isset($baseStructure[$section]))
			{
				continue;
			}

			if (!is_array($structure[$section]))
			{
				$structure[$section] = [];
			}

			$this->mergeBaseItemsIntoCustom(
				$baseStructure[$section],
				$structure[$section],
				$existingIds,
				false,
			);
		}

		return $structure;
	}

	public static function isAvailable(): bool
	{
		if (Main\Loader::includeModule('bitrix24'))
		{
			return \Bitrix\Bitrix24\Feature::isFeatureEnabled('intranet_menu_to_all');
		}

		return true;
	}

	public function getItems(): array
	{
		static $result;
		if ($result)
		{
			return $result;
		}
		$result = parent::getItems();
		$items = unserialize(
			\COption::GetOptionString('intranet', 'left_menu_custom_preset_items', ''),
			['allowed_classes' => false],
		);
		$items = (is_array($items) ? $items : []);
		foreach ($items as $itemData)
		{
			$item = new LeftMenu\MenuItem\ItemAdminCustom(array_merge([
				'ID' => $itemData['ID'],
				'TEXT' => $itemData['TEXT'],
				'LINK' => $itemData['LINK'],
				'COUNTER_ID' => $itemData['COUNTER_ID'] ?? null,
				'SUB_LINK' => $itemData['SUB_LINK'] ?? null,
				'NEW_PAGE' => $itemData['NEW_PAGE'] ?? null,
				'ADDITIONAL_LINKS' => $itemData['ADDITIONAL_LINKS'] ?? [],
			] , $itemData));
			$result[] = $item;
		}

		return $result;
	}

	private function collectIds(array $structure): array
	{
		$ids = [];
		$walker = static function ($value) use (&$walker, &$ids) {
			if (is_array($value))
			{
				array_walk($value, $walker);
			}
			else
			{
				$ids[] = (string)$value;
			}
		};
		array_walk($structure, $walker);

		return array_unique($ids);
	}

	private function mergeBaseItemsIntoCustom(array $baseSection, array &$customSection, array &$existingIds, bool $inGroup): void
	{
		foreach ($baseSection as $key => $value)
		{
			if (is_array($value))
			{
				if (isset($customSection[$key]) && is_array($customSection[$key]))
				{
					$this->mergeBaseItemsIntoCustom($value, $customSection[$key], $existingIds, true);
				}

				continue;
			}

			if ($inGroup && !in_array($value, $existingIds, true))
			{
				$customSection[] = $value;
				$existingIds[] = $value;
			}
		}
	}
}
