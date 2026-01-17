<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use ArrayIterator;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;
use BackedEnum;

abstract class AbstractBackedEnumCollection implements BackedEnumCollectionInterface
{
	#[Validatable(iterable: true)]
	protected array $items = [];

	abstract protected static function getEnumClass(): string;

	public function __construct(BackedEnum ...$items)
	{
		$enumClass = static::getEnumClass();

		foreach ($items as $item)
		{
			if (!$item instanceof $enumClass)
			{
				throw new ArgumentException();
			}
		}

		$this->items = $items;
	}

	public function contains(BackedEnum $enum): bool
	{
		return in_array($enum, $this->items, true);
	}

	public function add(BackedEnum $item): void
	{
		if ($item::class !== static::getEnumClass())
		{
			throw new ArgumentException();
		}

		$this->items[] = $item;
	}

	public static function mapFromArray(array $props): static
	{
		$items = [];
		foreach ($props as $prop)
		{
			if (!is_string($prop) && !is_int($prop))
			{
				continue;
			}

			/** @var BackedEnum $itemClass */
			$itemClass = static::getEnumClass();
			$item = $itemClass::tryFrom($prop);

			if ($item === null)
			{
				continue;
			}

			$items[] = $item;
		}

		return new static(...$items);
	}

	public function getIterator(): ArrayIterator
	{
		return new ArrayIterator($this->items);
	}

	public function toArray(): array
	{
		return array_map(static fn (BackedEnum $collectionItem): int|string => $collectionItem->value, $this->items);
	}

	public function getItems(): array
	{
		return $this->items;
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function isEmpty(): bool
	{
		return $this->count() === 0;
	}

	public function getFirstItem(): ?BackedEnum
	{
		if (!$this->count())
		{
			return null;
		}

		return $this->items[0];
	}

	public function merge(self $collection): static
	{
		foreach ($collection as $item)
		{
			$this->add($item);
		}

		return $this;
	}

	public function filter(callable $callback): static
	{
		return new static(...array_filter($this->items, $callback));
	}

	public function unique(): static
	{
		$unique = [];
		foreach ($this->items as $item)
		{
			if (!in_array($item->value, $unique, true))
			{
				$unique[] = $item->value;
			}
		}

		return new static(...$unique);
	}
}
