<?php

declare(strict_types=1);

namespace Bitrix\Disk\Destination;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internals\ObjectPathTable;

/**
 * Objects of one group operation, in the order they were chosen.
 *
 * An object is dropped when one of its ancestors is chosen as well: it travels with that ancestor, so a copy
 * would duplicate it and a move would carry it out of the ancestor. Everything weighed over a selection - the
 * verdict, the size, the names, the operation itself - sees only the objects left, and their subtrees never
 * intersect.
 *
 * @implements \IteratorAggregate<int, BaseObject>
 */
final class ObjectSelection implements \IteratorAggregate, \Countable
{
	public const MAX_SIZE = 100;
	public const MIN_SIZE = 2;

	/**
	 * @param array<int, BaseObject> $objects by id, in the order of the selection
	 * @param int[] $droppedIds
	 */
	private function __construct(
		private readonly array $objects,
		private readonly array $droppedIds,
	)
	{
	}

	/**
	 * @throws \InvalidArgumentException when an object is given twice
	 */
	public static function normalized(BaseObject ...$objects): self
	{
		$objectsById = [];
		foreach ($objects as $object)
		{
			$id = (int)$object->getId();
			if (isset($objectsById[$id]))
			{
				throw new \InvalidArgumentException("Object {$id} is selected twice.");
			}

			$objectsById[$id] = $object;
		}

		$droppedIds = self::loadIdsUnderSelectedAncestor(array_keys($objectsById));

		return new self(
			array_diff_key($objectsById, array_flip($droppedIds)),
			$droppedIds,
		);
	}

	/**
	 * @return int[]
	 */
	public function ids(): array
	{
		return array_keys($this->objects);
	}

	public function getById(int $id): ?BaseObject
	{
		return $this->objects[$id] ?? null;
	}

	/**
	 * @return int[] objects left out because an ancestor of theirs is selected, in the order of the selection
	 */
	public function droppedIds(): array
	{
		return $this->droppedIds;
	}

	public function getIterator(): \ArrayIterator
	{
		return new \ArrayIterator(array_values($this->objects));
	}

	public function count(): int
	{
		return count($this->objects);
	}

	/**
	 * The closure table holds a row for every pair of an object and its ancestor, so the descendants among
	 * the selection are the rows whose both ends are selected. The row of an object to itself is left aside.
	 *
	 * @param int[] $ids
	 * @return int[]
	 */
	private static function loadIdsUnderSelectedAncestor(array $ids): array
	{
		if (count($ids) < 2)
		{
			return [];
		}

		$rows = ObjectPathTable::query()
			->setSelect(['OBJECT_ID', 'PARENT_ID'])
			->whereIn('OBJECT_ID', $ids)
			->whereIn('PARENT_ID', $ids)
			->exec()
		;

		$descendantIds = [];
		while ($row = $rows->fetch())
		{
			$objectId = (int)$row['OBJECT_ID'];
			if ($objectId !== (int)$row['PARENT_ID'])
			{
				$descendantIds[$objectId] = true;
			}
		}

		return array_values(array_filter(
			$ids,
			static fn(int $id): bool => isset($descendantIds[$id]),
		));
	}
}
