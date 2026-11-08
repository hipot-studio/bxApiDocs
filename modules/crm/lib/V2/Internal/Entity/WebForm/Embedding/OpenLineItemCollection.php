<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, OpenLineItem>
 * @internal
 */
final class OpenLineItemCollection implements Countable, IteratorAggregate
{
	/** @var OpenLineItem[] */
	private array $items;

	public function __construct(OpenLineItem ...$items)
	{
		$this->items = $items;
	}

	/**
	 * @return OpenLineItem[]
	 */
	public function getAll(): array
	{
		return $this->items;
	}

	/**
	 * @return int[]
	 */
	public function getIds(): array
	{
		return array_map(static fn(OpenLineItem $item): int => $item->id, $this->items);
	}

	public function isEmpty(): bool
	{
		return $this->items === [];
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->items);
	}
}
