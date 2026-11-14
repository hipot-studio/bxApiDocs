<?php

declare(strict_types=1);

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\Location\Entity\Source;
use Bitrix\Main\Application;
use Bitrix\StaffTrack\Internal\Service\Snapshot\SnapshotFileIdCache;
use Bitrix\StaffTrack\Internal\Service\Snapshot\StaticMapSnapshotService;
use Bitrix\StaffTrack\Public\Provider\GeoProvider;

class PointSnapshot
{
	private const LOCK_PREFIX = 'stafftrack.point.snapshot.';

	protected readonly StaticMapSnapshotService $snapshots;
	protected readonly SnapshotFileIdCache $cache;

	public function __construct(
		?StaticMapSnapshotService $snapshots = null,
		?SnapshotFileIdCache $cache = null,
	)
	{
		$this->snapshots = $snapshots ?? new StaticMapSnapshotService();
		$this->cache = $cache ?? new SnapshotFileIdCache(SnapshotCache::CACHE_DIR, SnapshotCache::CACHE_ID_PREFIX);
	}

	public function getStoredUrl(float $lat, float $lon): ?string
	{
		$hash = $this->buildHash($lat, $lon);

		return $hash === null ? null : $this->resolveStoredUrl($hash);
	}

	public function renderUrl(float $lat, float $lon): ?string
	{
		$hash = $this->buildHash($lat, $lon);
		if ($hash === null)
		{
			return null;
		}

		$storedUrl = $this->resolveStoredUrl($hash);
		if ($storedUrl !== null || $this->cache->hasRecentFailure($hash))
		{
			return $storedUrl;
		}

		if (!$this->lock($hash))
		{
			return null;
		}

		try
		{
			$storedUrl = $this->resolveStoredUrl($hash);
			if ($storedUrl !== null || $this->cache->hasRecentFailure($hash))
			{
				return $storedUrl;
			}

			return $this->capture($lat, $lon, $hash);
		}
		finally
		{
			$this->unlock($hash);
		}
	}

	protected function lock(string $hash): bool
	{
		return Application::getConnection()->lock(self::LOCK_PREFIX . $hash);
	}

	protected function unlock(string $hash): void
	{
		Application::getConnection()->unlock(self::LOCK_PREFIX . $hash);
	}

	protected function makeGeoProvider(float $lat, float $lon): GeoProvider
	{
		return new GeoProvider($lat, $lon);
	}

	private function capture(float $lat, float $lon, string $hash): ?string
	{
		$result = $this->makeGeoProvider($lat, $lon)->generateSnapshot();
		$url = $result->isSuccess() ? (string)($result->getData()['snapshotUrl'] ?? '') : '';
		if ($url === '')
		{
			$this->cache->rememberFailure($hash);

			return null;
		}

		return $url;
	}

	private function buildHash(float $lat, float $lon): ?string
	{
		$sourceResult = $this->snapshots->resolveSource();
		if (!$sourceResult->isSuccess())
		{
			return null;
		}

		/** @var Source $source */
		$source = $sourceResult->getData()['source'];

		return GeoProvider::buildSnapshotHash(
			$source->getCode(),
			StaticMapSnapshotService::roundCoordinate($lat),
			StaticMapSnapshotService::roundCoordinate($lon),
		);
	}

	private function resolveStoredUrl(string $hash): ?string
	{
		$fileId = $this->cache->getFileId($hash);

		return $fileId === null ? null : $this->snapshots->getFilePath($fileId);
	}
}
