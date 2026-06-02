<?php

namespace Bitrix\Crm\Service\Timeline\Layout;

use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItem;

class Menu extends Base
{
	protected ?MenuItem $deleteItem = null;
	protected ?MenuItem $pinItem = null;

	protected array $menuItems = [];

	protected int $currentSort = 0;
	protected const SORT_STEP = 100;

	protected array $sections = [];

	public function addItem(string $id, MenuItem $item): self
	{
		if ($item->getSort() === null)
		{
			$item->setSort($this->currentSort);
			$this->currentSort += self::SORT_STEP;
		}

		$this->menuItems[$id] = $item;

		return $this;
	}

	/**
	 * @return MenuItem[]
	 */
	public function getItems(): array
	{
		return $this->menuItems;
	}

	/**
	 * @param MenuItem[] $menuItems
	 */
	public function setItems(array $menuItems): self
	{
		$this->menuItems = [];
		foreach ($menuItems as $id => $menuItem)
		{
			if (is_null($menuItem))
			{
				continue;
			}

			$this->addItem((string)$id, $menuItem);
		}

		return $this;
	}

	public function getItemById(string $id): ?MenuItem
	{
		return ($this->menuItems[$id] ?? null);
	}

	/**
	 * Adds a section definition in the output order expected by `ui.system.menu`.
	 */
	public function addSection(string $code, ?string $title = null, ?string $design = null): self
	{
		$section = ['code' => $code];
		if ($title !== null)
		{
			$section['title'] = $title;
		}
		if ($design !== null)
		{
			$section['design'] = $design;
		}
		$this->sections[] = $section;

		return $this;
	}

	/**
	 * Replaces explicit section definitions for the current menu level.
	 *
	 * @param array<int, array{code: string, title?: string, design?: string}> $sections
	 */
	public function setSections(array $sections): self
	{
		$this->sections = $sections;

		return $this;
	}

	/**
	 * Returns explicit section definitions for the current menu level.
	 *
	 * @return array<int, array{code: string, title?: string, design?: string}>
	 */
	public function getSections(): array
	{
		return $this->sections;
	}

	public function toArray(): array
	{
		return [
			'items' => $this->getItems(),
			'sections' => empty($this->sections) ? null : $this->sections,
		];
	}
}
