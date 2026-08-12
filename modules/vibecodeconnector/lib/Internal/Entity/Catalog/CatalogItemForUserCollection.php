<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\Catalog;

/**
 * @implements \IteratorAggregate<int, CatalogItemForUser>
 */
final class CatalogItemForUserCollection implements \IteratorAggregate, \Countable
{
	/** @var CatalogItemForUser[] */
	private readonly array $items;

	public function __construct(CatalogItemForUser ...$items)
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

	/** @return CatalogItemForUser[] */
	public function toArray(): array
	{
		return $this->items;
	}
}
