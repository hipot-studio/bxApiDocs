<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Favorite;

use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Internal\Entity\FavoriteMark;
use Bitrix\Disk\Internal\Repository\FavoriteMarkRepository;

/**
 * Keeps the favorite marks in line with the access of their users: a mark lives while its user reads the object.
 */
final class FavoriteAccessReconciler
{
	private const OBJECTS_PER_QUERY = 500;

	public function __construct(
		private readonly FavoriteMarkRepository $repository,
	)
	{
	}

	public function isActiveAndReadable(int $userId, BaseObject $object): bool
	{
		return !$object->isDeleted() && $this->canRead($userId, $object);
	}

	/**
	 * Drops the marks whose object is gone or no longer read by the user of the mark. A mark of an object in the
	 * trashcan is kept: the restored object brings it back.
	 *
	 * @param FavoriteMark[] $marks
	 * @return int number of the dropped marks
	 */
	public function reconcile(array $marks): int
	{
		if ($marks === [])
		{
			return 0;
		}

		$objects = $this->loadObjects(array_map(static fn (FavoriteMark $mark): int => $mark->objectId, $marks));

		$droppedIds = [];
		foreach ($marks as $mark)
		{
			$object = $objects[$mark->objectId] ?? null;
			if ($object === null)
			{
				$droppedIds[] = $mark->id;

				continue;
			}

			if (!$object->isDeleted() && !$this->canRead($mark->userId, $object))
			{
				$droppedIds[] = $mark->id;
			}
		}

		$this->repository->deleteByIds($droppedIds);

		return count($droppedIds);
	}

	private function canRead(int $userId, BaseObject $object): bool
	{
		$storage = $object->getStorage();
		if ($storage === null)
		{
			return false;
		}

		// A fresh context: the one cached on the storage keeps the operations read earlier in the hit,
		// so the access taken away since then would still look granted.
		return $object->canRead($storage->getProxyType()->getSecurityContextByUser($userId));
	}

	/**
	 * @param int[] $objectIds
	 * @return array<int, BaseObject>
	 */
	private function loadObjects(array $objectIds): array
	{
		$objects = [];
		foreach (array_chunk(array_values(array_unique($objectIds)), self::OBJECTS_PER_QUERY) as $chunk)
		{
			foreach (BaseObject::loadBatchById($chunk) as $object)
			{
				if ($object instanceof BaseObject)
				{
					$objects[(int)$object->getId()] = $object;
				}
			}
		}

		return $objects;
	}
}
