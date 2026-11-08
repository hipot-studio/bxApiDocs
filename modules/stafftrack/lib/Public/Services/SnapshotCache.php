<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\StaffTrack\Internal\Service\Snapshot\SnapshotFileIdCache;

class SnapshotCache
{
	public const CACHE_DIR = '/stafftrack/snapshot';
	public const CACHE_ID_PREFIX = 'snap_';

	public static function getFileId(string $hash): ?int
	{
		return (new SnapshotFileIdCache(self::CACHE_DIR, self::CACHE_ID_PREFIX))->getFileId($hash);
	}

	public static function setFileId(string $hash, int $fileId): void
	{
		(new SnapshotFileIdCache(self::CACHE_DIR, self::CACHE_ID_PREFIX))->setFileId($hash, $fileId);
	}
}
