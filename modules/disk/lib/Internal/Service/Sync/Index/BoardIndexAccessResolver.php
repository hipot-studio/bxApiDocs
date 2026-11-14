<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Index;

use Bitrix\Disk\Internal\Service\Sync\Snapshot\AccessSourceConfirmerInterface;
use Bitrix\Disk\Internal\Service\Sync\Visibility\AccessSourceConfirmer;
use Bitrix\Disk\Internals\TrackedObjectTable;

final class BoardIndexAccessResolver
{
	private AccessSourceConfirmerInterface $accessSourceConfirmer;

	public function __construct(?AccessSourceConfirmerInterface $accessSourceConfirmer = null)
	{
		$this->accessSourceConfirmer = $accessSourceConfirmer ?? new AccessSourceConfirmer();
	}

	public function canRead(int $userId, int $realObjectId): bool
	{
		if ($userId <= 0 || $realObjectId <= 0)
		{
			return false;
		}

		if (isset($this->accessSourceConfirmer->visibleByDiskRights([$realObjectId], $userId)[$realObjectId]))
		{
			return true;
		}
		$rows = TrackedObjectTable::query()
			->setSelect(['OBJECT_ID', 'REAL_OBJECT_ID', 'ATTACHED_OBJECT_ID', 'UPDATE_TIME'])
			->where('USER_ID', $userId)
			->where('REAL_OBJECT_ID', $realObjectId)
			->fetchAll()
		;
		$trackedRows = array_map(
			static fn (array $row): array => [
				'objectId' => (int)$row['OBJECT_ID'],
				'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				'freshnessAt' => $row['UPDATE_TIME'],
				'attachedObjectId' => $row['ATTACHED_OBJECT_ID'] === null
					? null
					: (int)$row['ATTACHED_OBJECT_ID'],
			],
			$rows,
		);

		return $this->accessSourceConfirmer->confirm($trackedRows, $userId) !== [];
	}
}
