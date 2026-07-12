<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Dto;

use ArrayIterator;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Type\Contract\Arrayable;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @method array getIdList()
 */
abstract class AbstractCollection implements IteratorAggregate, Countable, Arrayable
{
	/** @var Arrayable[] */
	private array $items = [];

	abstract protected static function getItemClass(): string;

	public function __construct(object ...$items)
	{
		foreach ($items as $item)
		{
			$this->assertValidItem($item);
		}

		$this->items = $items;
	}

	public function add(object $item): void
	{
		$this->assertValidItem($item);
		$this->items[] = $item;
	}

	public function findOneById(mixed $id, string $idKey = 'id'): ?object
	{
		return $this->findOne([$idKey => $id]);
	}

	public function findAllByIds(array $ids, string $idKey = 'id'): static
	{
		return $this->findAll([$idKey => $ids]);
	}

	public function findOne(array $conditions): ?object
	{
		foreach ($this->items as $item)
		{
			foreach ($conditions as $key => $value)
			{
				if (!property_exists($item, $key) || $item->{$key} !== $value)
				{
					continue 2;
				}
			}

			return $item;
		}

		return null;
	}

	public function findAll(array $conditions): static
	{
		$result = [];
		foreach ($this->items as $item)
		{
			foreach ($conditions as $key => $value)
			{
				if (!property_exists($item, $key))
				{
					continue 2;
				}

				if (is_array($value))
				{
					if (!in_array($item->{$key}, $value, true))
					{
						continue 2;
					}
				}
				elseif ($item->{$key} !== $value)
				{
					continue 2;
				}
			}

			$result[] = $item;
		}

		return new static(...$result);
	}

	public function __call(string $name, array $args = []): ?array
	{
		$operation = substr($name, 0, 3);
		$property = lcfirst(substr($name, 3));
		$subOperation = lcfirst(substr($property, -4));

		if ($operation === 'get' && $subOperation === 'list')
		{
			$property = substr($property, 0, -4);

			return array_column($this->toArray(), $property);
		}

		return null;
	}

	public function getIds(): array
	{
		return array_values(array_filter(
			array_map(
				static fn (object $item): mixed => method_exists($item, 'getId') ? $item->getId() : null,
				$this->items
			),
			static fn (mixed $id): bool => $id !== null
		));
	}

	public static function mapFromArray(array $props): static
	{
		$items = [];
		foreach ($props as $prop)
		{
			if (!is_array($prop))
			{
				continue;
			}

			$itemClass = static::getItemClass();
			$items[] = $itemClass::mapFromArray($prop);
		}

		return new static(...$items);
	}

	public static function mapFromIds(array $ids, string $idKey = 'id'): static
	{
		$items = [];
		$itemClass = static::getItemClass();
		foreach ($ids as $id)
		{
			$items[] = $itemClass::mapFromArray([$idKey => $id]);
		}

		return new static(...$items);
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this->items);
	}

	public function count(): int
	{
		return count($this->items);
	}

	public function isEmpty(): bool
	{
		return $this->count() === 0;
	}

	public function toArray(): array
	{
		return array_map(
			static fn (Arrayable $item): array => $item->toArray(),
			$this->items
		);
	}

	public function getFirstEntity(): ?object
	{
		return $this->items[0] ?? null;
	}

	public function find(callable $callback): ?object
	{
		foreach ($this->items as $key => $item)
		{
			if ($callback($item, $key))
			{
				return $item;
			}
		}

		return null;
	}

	public function filter(callable $callback): static
	{
		return new static(...array_values(array_filter($this->items, $callback)));
	}

	public function map(callable $callback): array
	{
		return array_map($callback, $this->items);
	}

	private function assertValidItem(object $item): void
	{
		$itemClass = static::getItemClass();
		if (!$item instanceof $itemClass)
		{
			throw new ArgumentException('Invalid item class for collection');
		}

		if (!$item instanceof Arrayable)
		{
			throw new ArgumentException('Collection item must implement Arrayable');
		}
	}
}
