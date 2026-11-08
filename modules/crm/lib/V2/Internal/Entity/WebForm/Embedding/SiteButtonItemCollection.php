<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm\Embedding;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, SiteButtonItem>
 * @internal
 */
final class SiteButtonItemCollection implements Countable, IteratorAggregate
{
	/** @var SiteButtonItem[] */
	private array $items;

	public function __construct(SiteButtonItem ...$items)
	{
		$this->items = $items;
	}

	/**
	 * @return SiteButtonItem[]
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
		return array_map(static fn(SiteButtonItem $item): int => $item->id, $this->items);
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
