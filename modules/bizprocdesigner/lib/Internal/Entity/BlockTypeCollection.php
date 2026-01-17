<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class BlockTypeCollection implements \IteratorAggregate, Arrayable
{
	/**
	 * @var list<BlockType>
	 */
	private array $items = [];

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}

	public function add(BlockType $item): static
	{
		$this->items[] = $item;

		return $this;
	}

	public function toArray(): array
	{
		return array_values(array_map(static fn(BlockType $item) => $item->toArray(), $this->items));
	}
}