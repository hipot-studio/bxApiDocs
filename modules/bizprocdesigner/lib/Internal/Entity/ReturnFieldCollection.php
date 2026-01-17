<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class ReturnFieldCollection implements \IteratorAggregate, Arrayable
{
	/**
	 * @var list<ReturnField>
	 */
	private array $items = [];

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}

	public function add(ReturnField $item): static
	{
		$this->items[] = $item;

		return $this;
	}

	public function toArray(): array
	{
		return array_map(static fn(ReturnField $item) => $item->toArray(), $this->items);
	}
}