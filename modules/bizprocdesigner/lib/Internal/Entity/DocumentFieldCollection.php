<?php

namespace Bitrix\BizprocDesigner\Internal\Entity;

use Bitrix\Main\Type\Contract\Arrayable;

class DocumentFieldCollection implements \IteratorAggregate, Arrayable
{
	/**
	 * @var list<DocumentField>
	 */
	private array $items = [];

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}

	public function add(DocumentField $item): static
	{
		$this->items[] = $item;

		return $this;
	}

	public function toArray(): array
	{
		return array_map(static fn(DocumentField $item) => $item->toArray(), $this->items);
	}
}