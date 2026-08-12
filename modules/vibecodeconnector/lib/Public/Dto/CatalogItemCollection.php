<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Dto;

/**
 * @implements \IteratorAggregate<int, CatalogItem>
 */
final class CatalogItemCollection implements \IteratorAggregate, \Countable
{
	/** @var CatalogItem[] */
	private readonly array $items;

	public function __construct(CatalogItem ...$items)
	{
		$this->items = $items;
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function isEmpty(): bool
	{
		return $this->items === [];
	}

	/** @return CatalogItem[] */
	public function toArray(): array
	{
		return $this->items;
	}
}
