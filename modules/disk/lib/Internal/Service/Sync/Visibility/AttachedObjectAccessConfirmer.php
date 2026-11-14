<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Visibility;

use Bitrix\Disk\AttachedObject;
use Throwable;

final class AttachedObjectAccessConfirmer
{
	/**
	 * @param int[] $attachedObjectIds
	 * @return array<int, true>
	 */
	public function readableIds(int $userId, array $attachedObjectIds): array
	{
		return $this->readableIdsInternal($userId, $attachedObjectIds);
	}

	/**
	 * @param int[] $attachedObjectIds
	 * @return array<int, true>
	 */
	public function readableIdsForRealObject(
		int $userId,
		array $attachedObjectIds,
		int $realObjectId,
	): array
	{
		if ($realObjectId <= 0)
		{
			return [];
		}

		return $this->readableIdsInternal($userId, $attachedObjectIds, $realObjectId);
	}

	/**
	 * @param int[] $attachedObjectIds
	 * @return array<int, true>
	 */
	private function readableIdsInternal(
		int $userId,
		array $attachedObjectIds,
		?int $realObjectId = null,
	): array
	{
		$attachedObjectIds = self::normaliseIds($attachedObjectIds);
		if ($attachedObjectIds === [])
		{
			return [];
		}

		try
		{
			$attachedObjects = AttachedObject::loadBatchById($attachedObjectIds, ['OBJECT']);
		}
		catch (Throwable)
		{
			return [];
		}

		$attachedObjectsById = [];
		foreach ($attachedObjects as $attachedObject)
		{
			$attachedObjectsById[(int)$attachedObject->getId()] = $attachedObject;
		}

		$readable = [];
		foreach ($attachedObjectIds as $attachedObjectId)
		{
			$attachedObject = $attachedObjectsById[$attachedObjectId] ?? null;
			if ($attachedObject === null)
			{
				continue;
			}

			try
			{
				$file = $attachedObject->getFile();
				if (
					$file !== null
					&& ($realObjectId === null || (int)$file->getRealObjectId() === $realObjectId)
					&& $attachedObject->canRead($userId)
				)
				{
					$readable[$attachedObjectId] = true;
				}
			}
			catch (Throwable)
			{
			}
		}

		return $readable;
	}

	/**
	 * @param int[] $attachedObjectIds
	 * @return int[]
	 */
	private static function normaliseIds(array $attachedObjectIds): array
	{
		$normalised = [];
		foreach ($attachedObjectIds as $attachedObjectId)
		{
			$attachedObjectId = (int)$attachedObjectId;
			if ($attachedObjectId > 0)
			{
				$normalised[$attachedObjectId] = $attachedObjectId;
			}
		}

		return array_values($normalised);
	}
}
