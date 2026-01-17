<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity;

use ArrayIterator;
use BackedEnum;
use Bitrix\Main\ArgumentException;
use IteratorAggregate;
use Traversable;

abstract class AbstractEntity implements EntityInterface, IteratorAggregate
{
	public static function mapFromId(mixed $id, string $idKey = 'id'): static
	{
		return static::mapFromArray([$idKey => $id]);
	}

	public function cloneWith(array $props): static
	{
		return static::mapFromArray([...$this->toArray(), ...$props]);
	}

	public function diff(AbstractEntity $entityToCompare): array
	{
		if (!$entityToCompare instanceof $this)
		{
			throw new ArgumentException('Entity to compare must be the same class');
		}

		$result = [];
		foreach ($this as $key => $value)
		{
			$valueToCompare = $entityToCompare->{$key};

			if ($value instanceof EntityInterface)
			{
				$id = $value->getId();
				$idToCompare = $valueToCompare?->getId();
				if ($id !== $idToCompare)
				{
					$result[$key]['id'] = $id;
				}

				continue;
			}

			if ($value instanceof EntityCollectionInterface)
			{
				$ids = $value->getIds();
				$idsToCompare = (array)$valueToCompare?->getIds();

				if (!empty(array_diff($ids, $idsToCompare)) || !empty(array_diff($idsToCompare, $ids)))
				{
					$result[$key] = array_map(static fn (mixed $id): array => ['id' => $id], $ids);
				}

				continue;
			}

			if ($value instanceof ValueObjectInterface)
			{
				if ($value->toArray() !== $valueToCompare?->toArray())
				{
					$result[$key] = $value->toArray();
				}

				continue;
			}

			if ($value instanceof BackedEnumCollectionInterface)
			{
				$value = $value->toArray();
				$valueToCompare = $valueToCompare?->toArray();
			}

			if (is_array($value))
			{
				$diff = array_diff($value, $valueToCompare ?? []);
				$reverseDiff = array_diff($valueToCompare ?? [], $value);
				if (!empty($diff) || !empty($reverseDiff))
				{
					$result[$key] = $value;
				}

				continue;
			}

			if ($value instanceof BackedEnum)
			{
				if ($value !== $valueToCompare)
				{
					$result[$key] = $value->value;
				}

				continue;
			}

			if ($value !== $valueToCompare)
			{
				$result[$key] = $value;
			}
		}

		return $result;
	}

	public function getIterator(): Traversable
	{
		return new ArrayIterator($this);
	}

	public function isEquals(AbstractEntity $entity): bool
	{
		return $this->getId() === $entity->getId();
	}

	public function isNotEquals(AbstractEntity $entity): bool
	{
		return !$this->isEquals($entity);
	}
}
