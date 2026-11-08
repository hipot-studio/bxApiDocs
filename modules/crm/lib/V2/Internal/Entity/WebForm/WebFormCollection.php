<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\WebForm;

use ArrayIterator;
use Countable;
use IteratorAggregate;

/**
 * @implements IteratorAggregate<int, WebForm>
 * @internal
 */
final class WebFormCollection implements Countable, IteratorAggregate
{
	/** @var WebForm[] */
	private array $items = [];

	public function __construct(WebForm ...$items)
	{
		foreach ($items as $item)
		{
			$this->add($item);
		}
	}

	public function add(WebForm $item): self
	{
		$this->items[] = $item;

		return $this;
	}

	public function first(): ?WebForm
	{
		return $this->items[0] ?? null;
	}

	public function getById(int $id): ?WebForm
	{
		foreach ($this->items as $item)
		{
			if ($item->id === $id)
			{
				return $item;
			}
		}

		return null;
	}

	/**
	 * @return int[]
	 */
	public function getIds(): array
	{
		return array_values(array_unique(array_map(
			static fn(WebForm $item): int => $item->id,
			$this->items,
		)));
	}

	/**
	 * @return WebForm[]
	 */
	public function getAll(): array
	{
		return $this->items;
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
