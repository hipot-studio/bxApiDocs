<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity\Collection;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @psalm-consistent-constructor
 * @template T of object
 * @implements IteratorAggregate<int, T>
 */
abstract class AbstractCollection implements ArrayAccess, Countable, IteratorAggregate
{
	protected array $items = [];

	public function __construct(array $items = [])
	{
		foreach ($items as $item)
		{
			if ($this->isValidItem($item))
			{
				$this->add($item);
			}
		}
	}

	/**
	 * @param mixed $item
	 * @return bool
	 */
	abstract protected function isValidItem(mixed $item): bool;

	/**
	 * @param T $item
	 * @return $this
	 */
	public function add(mixed $item): self
	{
		if ($this->isValidItem($item))
		{
			$this->items[] = $item;
		}

		return $this;
	}

	/**
	 * @return array<T>
	 */
	public function getAll(): array
	{
		return $this->items;
	}

	public function offsetExists($offset): bool
	{
		return isset($this->items[$offset]);
	}

	/**
	 * @param int $offset
	 *
	 * @return T
	 */
	public function offsetGet($offset): mixed
	{
		return $this->items[$offset];
	}

	public function offsetSet($offset, $value): void
	{
		if ($this->isValidItem($value))
		{
			if ($offset === null)
			{
				$this->add($value);
			}
			else
			{
				$this->items[$offset] = $value;
			}
		}
	}

	public function offsetUnset($offset): void
	{
		unset($this->items[$offset]);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->items);
	}
}
