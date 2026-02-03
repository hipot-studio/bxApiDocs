<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Application\Navigation;

use Bitrix\Im\V2\Rest\RestConvertible;
use Countable;
use IteratorAggregate;
use ArrayIterator;

/**
 * @implements IteratorAggregate<int, MenuItem>
 */
class MenuItemCollection implements IteratorAggregate, Countable, RestConvertible
{
	/** @var MenuItem[] */
	protected array $items = [];

	/**
	 * @param MenuItem[] $items
	 */
	public function __construct(array $items = [])
	{
		foreach ($items as $item)
		{
			$this->add($item);
		}
	}

	/**
	 * Adds an item to the collection.
	 */
	public function add(MenuItem $item): self
	{
		$this->items[] = $item;

		return $this;
	}

	/**
	 * Returns the first item found with the specified ID.
	 */
	public function get(string $id): ?MenuItem
	{
		foreach ($this->items as $item)
		{
			if ($item->getId() === $id)
			{
				return $item;
			}
		}

		return null;
	}

	public function has(string $id): bool
	{
		return $this->findFirstIndex($id) !== null;
	}

	/**
	 * Sorts items by their 'sort' property.
	 * Should be called after all modifications are done.
	 */
	public function sort(): self
	{
		usort($this->items, static fn(MenuItem $a, MenuItem $b) => $a->getSort() <=> $b->getSort());

		return $this;
	}

	/**
	 * Removes all items with the specified ID.
	 */
	public function remove(string $id): self
	{
		$this->items = array_filter($this->items, static fn (MenuItem $item) => $item->getId() !== $id);

		$this->items = array_values($this->items);

		return $this;
	}

	public static function getRestEntityName(): string
	{
		return 'menuItems';
	}

	/**
	 * @return array<array{id: string, text: string, entityId: int|null}>
	 */
	public function toRestFormat(array $option = []): array
	{
		$this->sort();

		$rest = [];
		foreach ($this->items as $item)
		{
			if ($item->isVisible())
			{
				$rest[] = $item->toArray();
			}
		}

		return $rest;
	}

	/**
	 * @return ArrayIterator<array-key, MenuItem>
	 */
	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	/**
	 * Finds the numerical index of the first item with the given ID.
	 *
	 * @param string $id
	 * @return int|null
	 */
	protected function findFirstIndex(string $id): ?int
	{
		foreach ($this->items as $index => $item)
		{
			if ($item->getId() === $id)
			{
				return (int)$index;
			}
		}

		return null;
	}
}
