<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Internal\Service\Office;

use Bitrix\Location\Entity\Source;
use Bitrix\StaffTrack\Internal\Repository\OfficeRepository;
use Bitrix\StaffTrack\Internal\Service\Snapshot\SnapshotFileIdCache;
use Bitrix\StaffTrack\Internal\Service\Snapshot\StaticMapSnapshotService;

class OfficeSnapshotService
{
	public const SUBDIR = 'stafftrack/office_map';

	private const CACHE_DIR = '/stafftrack/office_snapshot';
	private const CACHE_ID_PREFIX = 'office_snap_';

	private StaticMapSnapshotService $snapshots;
	private SnapshotFileIdCache $cache;
	private OfficeRepository $officeRepository;

	public function __construct(
		?StaticMapSnapshotService $snapshots = null,
		?SnapshotFileIdCache $cache = null,
		?OfficeRepository $officeRepository = null,
	)
	{
		$this->snapshots = $snapshots ?? new StaticMapSnapshotService();
		$this->cache = $cache ?? new SnapshotFileIdCache(self::CACHE_DIR, self::CACHE_ID_PREFIX);
		$this->officeRepository = $officeRepository ?? new OfficeRepository();
	}

	public function getUrl(int $officeId, float $latitude, float $longitude): ?string
	{
		$request = $this->makeRequest($officeId, $latitude, $longitude);
		if ($request === null)
		{
			return null;
		}

		$knownPath = $this->resolveKnownSnapshot($officeId, $request['hash']);
		if ($knownPath !== null)
		{
			return $knownPath;
		}

		if ($this->cache->hasRecentFailure($request['hash']))
		{
			return null;
		}

		return $this->captureUnderLock(
			$officeId,
			$request['source'],
			$request['latitude'],
			$request['longitude'],
			$request['hash'],
		);
	}

	public function canCapture(int $officeId, float $latitude, float $longitude): bool
	{
		$request = $this->makeRequest($officeId, $latitude, $longitude);
		if ($request === null)
		{
			return false;
		}

		return !$this->cache->hasRecentFailure($request['hash']);
	}

	public function getStoredUrl(int $officeId, float $latitude, float $longitude): ?string
	{
		$request = $this->makeRequest($officeId, $latitude, $longitude);
		if ($request === null)
		{
			return null;
		}

		return $this->resolveKnownSnapshot($officeId, $request['hash']);
	}

	/**
	 * @return array{source: Source, latitude: float, longitude: float, hash: string}|null
	 */
	private function makeRequest(int $officeId, float $latitude, float $longitude): ?array
	{
		if ($officeId <= 0)
		{
			return null;
		}

		$sourceResult = $this->snapshots->resolveSource();
		if (!$sourceResult->isSuccess())
		{
			return null;
		}

		/** @var Source $source */
		$source = $sourceResult->getData()['source'];

		$latitude = StaticMapSnapshotService::roundCoordinate($latitude);
		$longitude = StaticMapSnapshotService::roundCoordinate($longitude);

		return [
			'source' => $source,
			'latitude' => $latitude,
			'longitude' => $longitude,
			'hash' => self::buildHash($source->getCode(), $officeId, $latitude, $longitude),
		];
	}

	/**
	 * The map of an office is taken by one request at a time: without the lock every employee opening
	 * a profile of the office at once would ask the map service for the same image. A request that did
	 * not get the lock answers without a map at once instead of holding a process until the winner is
	 * done: it has nothing to show meanwhile, and the next open reads what the winner stored.
	 */
	private function captureUnderLock(
		int $officeId,
		Source $source,
		float $latitude,
		float $longitude,
		string $hash,
	): ?string
	{
		if (!$this->officeRepository->lockSnapshot($officeId))
		{
			return null;
		}

		try
		{
			$knownPath = $this->resolveKnownSnapshot($officeId, $hash);
			if ($knownPath !== null)
			{
				return $knownPath;
			}

			if ($this->cache->hasRecentFailure($hash))
			{
				return null;
			}

			return $this->capture($officeId, $source, $latitude, $longitude, $hash);
		}
		finally
		{
			$this->officeRepository->unlockSnapshot($officeId);
		}
	}

	private function capture(
		int $officeId,
		Source $source,
		float $latitude,
		float $longitude,
		string $hash,
	): ?string
	{
		$captured = $this->snapshots->capture($source, $latitude, $longitude);
		if (!$captured->isSuccess())
		{
			$this->cache->rememberFailure($hash);

			return null;
		}

		$fileId = $this->snapshots->save(
			$captured->getData()['snapshot'],
			self::buildFileName($officeId, $hash),
			self::SUBDIR . '/' . $officeId,
		);

		if ($fileId === null)
		{
			$this->cache->rememberFailure($hash);

			return null;
		}

		if (!$this->rememberFile($officeId, $fileId))
		{
			$this->snapshots->deleteFile($fileId);

			return null;
		}

		$this->cache->setFileId($hash, $fileId);

		return $this->snapshots->getFilePath($fileId);
	}

	public function deleteFile(int $fileId): void
	{
		if ($fileId > 0)
		{
			$this->snapshots->deleteFile($fileId);
		}
	}

	private function rememberFile(int $officeId, int $fileId): bool
	{
		$previousFileId = $this->officeRepository->getSnapshotFileId($officeId);
		if ($previousFileId === $fileId)
		{
			return true;
		}

		if (!$this->officeRepository->replaceSnapshotFileId($officeId, $previousFileId, $fileId))
		{
			return false;
		}

		if ($previousFileId !== null)
		{
			$this->snapshots->deleteFile($previousFileId);
		}

		return true;
	}

	private function resolveKnownSnapshot(int $officeId, string $hash): ?string
	{
		return $this->resolveCachedSnapshot($hash) ?? $this->resolveStoredSnapshot($officeId, $hash);
	}

	private function resolveCachedSnapshot(string $hash): ?string
	{
		$fileId = $this->cache->getFileId($hash);
		if ($fileId === null)
		{
			return null;
		}

		return $this->snapshots->getFilePath($fileId);
	}

	/**
	 * The map of the office outlives the cache of its identifier, and the name of the file names the
	 * coordinates it was taken at: an office that has not moved is answered from its own row instead
	 * of asking the map service again.
	 */
	private function resolveStoredSnapshot(int $officeId, string $hash): ?string
	{
		$fileId = $this->officeRepository->getSnapshotFileId($officeId);
		if ($fileId === null)
		{
			return null;
		}

		$fileName = $this->snapshots->getFileName($fileId);
		if ($fileName === null || !str_starts_with($fileName, self::buildFileName($officeId, $hash) . '.'))
		{
			return null;
		}

		$path = $this->snapshots->getFilePath($fileId);
		if ($path === null)
		{
			return null;
		}

		$this->cache->setFileId($hash, $fileId);

		return $path;
	}

	private static function buildFileName(int $officeId, string $hash): string
	{
		return $officeId . '_' . $hash;
	}

	private static function buildHash(string $sourceCode, int $officeId, float $latitude, float $longitude): string
	{
		return sha1(implode(';', [
			$sourceCode,
			$officeId,
			$longitude,
			$latitude,
			StaticMapSnapshotService::IMAGE_ZOOM,
			StaticMapSnapshotService::IMAGE_WIDTH,
			StaticMapSnapshotService::IMAGE_HEIGHT,
		]));
	}
}
