<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Timeline\Navigation\Catalog;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Json;

final class CatalogRunStore
{
	private static ?array $localIndex = null;
	private static array $localStates = [];
	private const STATE_LIMIT = 512 * 1024;
	private const ROW_LIMIT = 2048;
	private const RESPONSE_LIMIT = 256 * 1024;
	private const PORTAL_RUN_LIMIT = 128;
	private const PORTAL_BYTES_LIMIT = 64 * 1024 * 1024;
	private const ACTOR_RUN_LIMIT = 16;
	private const RECLAIM_STATE_READ_LIMIT = 16;
	private const TTL = 1800;
	private const CACHE_DIR = 'crm/timeline-navigation/catalog';
	private const INDEX_KEY = 'crm:timeline-nav:v1:index';
	private const LOCK_PREFIX = 'crm.timeline-nav.catalog.';

	public function hasRun(string $runId): bool
	{
		return isset($this->readIndex(false)['runs'][$runId]);
	}

	public function start(CatalogRunBinding $binding, string $catalogDigest): CatalogRunLease
	{
		$bindingHash = $binding->hash();
		$actorKey = $binding->actorKey();
		$runId = $this->uuid();

		return $this->withLocks(
			[$this->portalLock(), $this->bindingLock($bindingHash), $this->runLock($runId)],
			function () use ($bindingHash, $actorKey, $catalogDigest, $runId): CatalogRunLease
			{
				$index = $this->readIndex();
				$this->reclaim($index);
				$this->writeIndex($index);
				$previousRunId = $index['bindings'][$bindingHash] ?? null;
				$previous = is_string($previousRunId) ? ($index['runs'][$previousRunId] ?? null) : null;

				$state = new CatalogRunState(
					$runId,
					1,
					$bindingHash,
					$catalogDigest,
					[],
					time() + self::TTL,
				);
				$bytes = $this->stateBytes($state);
				$actorDelta = $actorKey !== null
					&& !(is_array($previous) && ($previous['actorKey'] ?? null) === $actorKey)
					? 1
					: 0;
				if ($actorKey !== null)
				{
					$this->assertActorQuota($index, $actorKey, $actorDelta);
				}
				$runDelta = is_array($previous) ? 0 : 1;
				$byteDelta = $bytes - (is_array($previous) ? (int)($previous['bytes'] ?? 0) : 0);
				$this->assertQuota($index, $runDelta, $byteDelta);
				$committedIndex = $index;
				$this->writeState($state);
				if (is_string($previousRunId))
				{
					$this->removeIndexedRun($index, $previousRunId, false);
				}
				$index['bindings'][$bindingHash] = $runId;
				$index['runs'][$runId] = [
					'bindingHash' => $bindingHash,
					'catalogDigest' => $catalogDigest,
					'bytes' => $bytes,
					'expiresAt' => $state->expiresAt,
					'version' => $state->version,
					'status' => 'committed',
				];
				if ($actorKey !== null)
				{
					$index['runs'][$runId]['actorKey'] = $actorKey;
				}
				$index['totalRuns']++;
				$index['totalBytes'] += $bytes;
				if ($actorKey !== null)
				{
					$index['actors'][$actorKey] = (int)($index['actors'][$actorKey] ?? 0) + 1;
				}
				try
				{
					$this->writeIndex($index);
				}
				catch (\Throwable $exception)
				{
					self::$localIndex = $committedIndex;
					$this->cleanState($runId);
					throw $exception;
				}
				if (is_string($previousRunId))
				{
					$this->cleanState($previousRunId);
				}

				return new CatalogRunLease($state);
			},
		);
	}

	public function load(
		string $runId,
		int $expectedVersion,
		CatalogRunBinding $binding,
		string $catalogDigest,
	): CatalogRunLease
	{
		$bindingHash = $binding->hash();

		return $this->withLocks(
			[$this->portalLock(), $this->bindingLock($bindingHash), $this->runLock($runId)],
			function () use ($runId, $expectedVersion, $bindingHash, $catalogDigest): CatalogRunLease
			{
				$index = $this->readIndex();
				$entry = $index['runs'][$runId] ?? null;
				if (
					is_array($entry)
					&& isset($entry['catalogDigest'])
					&& $entry['catalogDigest'] !== $catalogDigest
				)
				{
					$this->removeIndexedRun($index, $runId);
					$this->writeIndex($index);
					throw new \RuntimeException('NAV_CATALOG_STALE');
				}
				$state = $this->readState($runId, false);
				if ($state === null || $state->expiresAt < time())
				{
					$this->removeIndexedRun($index, $runId);
					$this->writeIndex($index);
					throw new \RuntimeException('NAV_CATALOG_EXPIRED');
				}
				if ($state->catalogDigest !== $catalogDigest)
				{
					$this->removeIndexedRun($index, $runId);
					$this->writeIndex($index);
					throw new \RuntimeException('NAV_CATALOG_STALE');
				}
				$this->assertCurrent($index, $state, $bindingHash, $expectedVersion);

				return new CatalogRunLease($state);
			},
		);
	}

	public function update(CatalogRunLease $lease, array $position): CatalogRunLease
	{
		$current = $lease->state;

		return $this->withLocks(
			[
				$this->portalLock(),
				$this->bindingLock($current->bindingHash),
				$this->runLock($current->runId),
			],
			function () use ($current, $position): CatalogRunLease
			{
				$index = $this->readIndex();
				$stored = $this->readState($current->runId);
				if ($stored === null || $stored->expiresAt < time())
				{
					$this->removeIndexedRun($index, $current->runId);
					$this->writeIndex($index);
					throw new \RuntimeException('NAV_CATALOG_EXPIRED');
				}
				$this->assertCurrent($index, $stored, $current->bindingHash, $current->version);
				$next = $stored->withPosition($position, time() + self::TTL);
				$nextBytes = $this->stateBytes($next);
				$previousBytes = (int)$index['runs'][$current->runId]['bytes'];
				$this->assertQuota($index, 0, $nextBytes - $previousBytes);
				$this->writeState($next);
				$index['runs'][$current->runId]['bytes'] = $nextBytes;
				$index['runs'][$current->runId]['expiresAt'] = $next->expiresAt;
				$index['runs'][$current->runId]['version'] = $next->version;
				$index['totalBytes'] += $nextBytes - $previousBytes;
				$this->writeIndex($index);

				return new CatalogRunLease($next);
			},
		);
	}

	public function finalize(CatalogRunLease $lease, array $rows): void
	{
		if (count($rows) > self::ROW_LIMIT || strlen(Json::encode($rows)) > self::RESPONSE_LIMIT)
		{
			$this->delete($lease->state->runId);
			throw new \RuntimeException('NAV_CATALOG_OVERFLOW');
		}

		$current = $lease->state;
		$this->withLocks(
			[
				$this->portalLock(),
				$this->bindingLock($current->bindingHash),
				$this->runLock($current->runId),
			],
			function () use ($current): void
			{
				$index = $this->readIndex();
				$stored = $this->readState($current->runId);
				if ($stored === null)
				{
					throw new \RuntimeException('NAV_CATALOG_EXPIRED');
				}
				$this->assertCurrent($index, $stored, $current->bindingHash, $current->version);
				$this->removeIndexedRun($index, $current->runId);
				$this->writeIndex($index);
			},
		);
	}

	public function delete(string $runId): void
	{
		$this->withLocks([$this->portalLock()], function () use ($runId): void
		{
			$index = $this->readIndex();
			$this->removeIndexedRun($index, $runId);
			$this->writeIndex($index);
		});
	}

	private function assertCurrent(
		array $index,
		CatalogRunState $state,
		string $bindingHash,
		int $expectedVersion,
	): void
	{
		$entry = $index['runs'][$state->runId] ?? null;
		if (
			!is_array($entry)
			|| ($index['bindings'][$bindingHash] ?? null) !== $state->runId
			|| ($entry['bindingHash'] ?? null) !== $bindingHash
			|| (int)($entry['version'] ?? 0) !== $expectedVersion
			|| $state->bindingHash !== $bindingHash
			|| $state->version !== $expectedVersion
		)
		{
			throw new \RuntimeException('NAV_CATALOG_VERSION');
		}
	}

	private function reclaim(array &$index): void
	{
		$now = time();
		$liveRunIds = [];
		foreach (array_keys($index['runs']) as $runId)
		{
			$entry = $index['runs'][$runId];
			if ((int)($entry['expiresAt'] ?? 0) < $now)
			{
				$this->removeIndexedRun($index, $runId);

				continue;
			}

			$liveRunIds[] = $runId;
		}

		$liveCount = count($liveRunIds);
		if ($liveCount === 0)
		{
			$index['reclaimCursor'] = 0;

			return;
		}

		$offset = (int)($index['reclaimCursor'] ?? 0) % $liveCount;
		$readCount = min(self::RECLAIM_STATE_READ_LIMIT, $liveCount);
		for ($i = 0; $i < $readCount; $i++)
		{
			$runId = $liveRunIds[($offset + $i) % $liveCount];
			if ($this->readState($runId) === null)
			{
				$this->removeIndexedRun($index, $runId);
			}
		}
		$index['reclaimCursor'] = ($offset + $readCount) % $liveCount;
	}

	private function removeIndexedRun(array &$index, string $runId, bool $cleanState = true): void
	{
		$entry = $index['runs'][$runId] ?? null;
		if (is_array($entry))
		{
			$bindingHash = (string)($entry['bindingHash'] ?? '');
			if (($index['bindings'][$bindingHash] ?? null) === $runId)
			{
				unset($index['bindings'][$bindingHash]);
			}
			$index['totalRuns'] = max(0, $index['totalRuns'] - 1);
			$index['totalBytes'] = max(0, $index['totalBytes'] - (int)($entry['bytes'] ?? 0));
			$actorKey = $entry['actorKey'] ?? null;
			if (is_string($actorKey) && $actorKey !== '' && isset($index['actors'][$actorKey]))
			{
				$remaining = (int)$index['actors'][$actorKey] - 1;
				if ($remaining > 0)
				{
					$index['actors'][$actorKey] = $remaining;
				}
				else
				{
					unset($index['actors'][$actorKey]);
					if ($index['actors'] === [])
					{
						unset($index['actors']);
					}
				}
			}
			unset($index['runs'][$runId]);
		}
		if ($cleanState)
		{
			$this->cleanState($runId);
		}
	}

	private function readIndex(bool $allowLocal = true): array
	{
		if ($allowLocal && self::$localIndex !== null)
		{
			return self::$localIndex;
		}
		$value = Application::getInstance()->getManagedCache()->getImmediate(
			self::TTL,
			self::INDEX_KEY,
			self::CACHE_DIR,
		);

		$index = is_array($value) ? $value + $this->emptyIndex() : $this->emptyIndex();

		if ($allowLocal)
		{
			self::$localIndex = $index;
		}

		return $index;
	}

	private function writeIndex(array $index): void
	{
		self::$localIndex = $index;
		$cache = Application::getInstance()->getManagedCache();
		$cache->read(self::TTL, self::INDEX_KEY, self::CACHE_DIR);
		$cache->setImmediate(self::INDEX_KEY, $index);
	}

	private function emptyIndex(): array
	{
		return [
			'bindings' => [],
			'runs' => [],
			'totalRuns' => 0,
			'totalBytes' => 0,
			'reclaimCursor' => 0,
		];
	}

	private function readState(string $runId, bool $allowLocal = true): ?CatalogRunState
	{
		$value = Application::getInstance()->getManagedCache()->getImmediate(
			self::TTL,
			$this->cacheKey($runId),
			self::CACHE_DIR,
		);

		if (is_array($value))
		{
			return self::$localStates[$runId] = CatalogRunState::fromArray($value);
		}

		return $allowLocal ? (self::$localStates[$runId] ?? null) : null;
	}

	private function writeState(CatalogRunState $state): void
	{
		$this->stateBytes($state);
		self::$localStates[$state->runId] = $state;
		$cache = Application::getInstance()->getManagedCache();
		$key = $this->cacheKey($state->runId);
		$cache->read(self::TTL, $key, self::CACHE_DIR);
		$cache->setImmediate($key, $state->toArray());
	}

	private function cleanState(string $runId): void
	{
		unset(self::$localStates[$runId]);
		Application::getInstance()->getManagedCache()->clean($this->cacheKey($runId), self::CACHE_DIR);
	}

	private function stateBytes(CatalogRunState $state): int
	{
		$bytes = strlen(Json::encode($state->toArray()));
		if ($bytes > self::STATE_LIMIT)
		{
			throw new \RuntimeException('NAV_CATALOG_OVERFLOW');
		}

		return $bytes;
	}

	private function assertQuota(array $index, int $runDelta, int $byteDelta): void
	{
		if (
			$index['totalRuns'] + $runDelta > self::PORTAL_RUN_LIMIT
			|| $index['totalBytes'] + $byteDelta > self::PORTAL_BYTES_LIMIT
		)
		{
			throw new \RuntimeException('NAV_CATALOG_OVERFLOW');
		}
	}

	private function assertActorQuota(array $index, string $actorKey, int $runDelta): void
	{
		if ((int)($index['actors'][$actorKey] ?? 0) + $runDelta > self::ACTOR_RUN_LIMIT)
		{
			throw new \RuntimeException('NAV_CATALOG_OVERFLOW');
		}
	}

	private function withLocks(array $names, callable $callback): mixed
	{
		$connection = Application::getConnection();
		$locked = [];
		try
		{
			foreach ($names as $name)
			{
				if (!$connection->lock($name, 5))
				{
					throw new \RuntimeException('NAV_RETRYABLE');
				}
				$locked[] = $name;
			}
			if (count($names) > 1)
			{
				$portalLock = $names[0];
				if (!$connection->lock($portalLock, 5))
				{
					throw new \RuntimeException('NAV_RETRYABLE');
				}
				$locked[] = $portalLock;
			}

			self::$localIndex = null;

			return $callback();
		}
		finally
		{
			self::$localIndex = null;
			foreach (array_reverse($locked) as $name)
			{
				$connection->unlock($name);
			}
		}
	}

	private function portalLock(): string
	{
		return self::LOCK_PREFIX . 'portal-index';
	}

	private function bindingLock(string $bindingHash): string
	{
		return self::LOCK_PREFIX . 'binding.' . substr($bindingHash, 0, 32);
	}

	private function runLock(string $runId): string
	{
		return self::LOCK_PREFIX . 'run.' . str_replace('-', '', $runId);
	}

	private function cacheKey(string $runId): string
	{
		return 'crm:timeline-nav:v1:run:' . $runId;
	}

	private function uuid(): string
	{
		$hex = bin2hex(random_bytes(16));

		return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
			. '-8' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
	}
}
