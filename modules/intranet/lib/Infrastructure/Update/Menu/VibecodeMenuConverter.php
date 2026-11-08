<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Infrastructure\Update\Menu;

final class VibecodeMenuConverter
{
	private const ITEM_ID = 'menu_vibecode';
	private const MARKETPLACE_ID = 'menu_marketplace_sect';
	private const GROUP_ID = 'menu_marketplace_group';

	public function convert(array $sort): array
	{
		if (
			!is_array($sort['show'] ?? null)
			|| !is_array($sort['hide'] ?? null)
			|| !$this->isValidTree($sort['show'])
			|| !$this->isValidTree($sort['hide'])
			|| $this->contains($sort['show'], self::ITEM_ID)
			|| $this->contains($sort['hide'], self::ITEM_ID)
		)
		{
			return $sort;
		}

		foreach (['show', 'hide'] as $visibility)
		{
			if ($this->insertIntoGroup($sort[$visibility]))
			{
				return $sort;
			}
		}

		foreach (['show', 'hide'] as $visibility)
		{
			if ($this->insertBeforeMarketplace($sort[$visibility]))
			{
				return $sort;
			}
		}

		return $sort;
	}

	private function isValidTree(array $items): bool
	{
		foreach ($items as $key => $item)
		{
			if (is_array($item))
			{
				if (isset($item['group_id']))
				{
					if (!is_string($item['group_id']) || $item['group_id'] === '' || ($item['items'] ?? []) !== [])
					{
						return false;
					}
				}
				elseif (!is_string($key) || $key === '' || !$this->isValidTree($item))
				{
					return false;
				}
			}
			elseif (!is_int($key) || !is_string($item) || $item === '')
			{
				return false;
			}
		}

		return true;
	}

	private function contains(array $items, string $id): bool
	{
		foreach ($items as $key => $item)
		{
			if ($key === $id || $item === $id || (is_array($item) && $this->contains($item, $id)))
			{
				return true;
			}
		}

		return false;
	}

	private function insertIntoGroup(array &$items): bool
	{
		foreach ($items as $key => &$item)
		{
			if ($key === self::GROUP_ID && is_array($item))
			{
				if (!$this->insertBeforeMarketplace($item))
				{
					array_unshift($item, self::ITEM_ID);
				}

				return true;
			}
			if (is_array($item) && ($item['group_id'] ?? null) === self::GROUP_ID)
			{
				$result = [];
				foreach ($items as $position => $value)
				{
					if ($position === $key)
					{
						$result[self::GROUP_ID] = [self::ITEM_ID];
					}
					elseif (is_int($position))
					{
						$result[] = $value;
					}
					else
					{
						$result[$position] = $value;
					}
				}
				$items = $result;

				return true;
			}

			if (is_array($item) && !isset($item['group_id']) && $this->insertIntoGroup($item))
			{
				return true;
			}
		}

		return false;
	}

	private function insertBeforeMarketplace(array &$items): bool
	{
		if (in_array(self::MARKETPLACE_ID, $items, true))
		{
			$result = [];
			$inserted = false;
			foreach ($items as $key => $item)
			{
				if (!$inserted && $item === self::MARKETPLACE_ID)
				{
					$result[] = self::ITEM_ID;
					$inserted = true;
				}
				if (is_int($key))
				{
					$result[] = $item;
				}
				else
				{
					$result[$key] = $item;
				}
			}
			$items = $result;

			return true;
		}

		foreach ($items as &$item)
		{
			if (is_array($item) && !isset($item['group_id']) && $this->insertBeforeMarketplace($item))
			{
				return true;
			}
		}

		return false;
	}
}
