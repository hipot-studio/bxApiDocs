<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Pairing;

use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Model\Pairing\PairingTable;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;

final class PairingRepository
{
	private const SELECT_FIELDS = [
		'ID',
		'ISS',
		'PUBLIC_KEY',
		'PORTAL_ID',
		'ENDPOINT_URL',
		'FETCHED_AT',
		'EXPIRES_AT',
		'PUBLIC_KEY_TTL',
		'KEY_SOURCE',
	];

	private const CACHE_DIR = '/vibecodeconnector/pairings/';
	private const CACHE_TTL_SECONDS = 30758400;

	public function findByIss(string $iss): ?Pairing
	{
		$cache = Cache::createInstance();
		$cacheKey = 'pairing_by_iss_' . md5($iss);
		if ($cache->initCache(self::CACHE_TTL_SECONDS, $cacheKey, self::CACHE_DIR))
		{
			$cached = $cache->getVars();

			return is_array($cached) ? $this->mapRow($cached) : null;
		}

		$row = PairingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('ISS', $iss)
			->setLimit(1)
			->fetch();

		if ($cache->startDataCache())
		{
			$cache->endDataCache($row !== false ? $row : null);
		}

		return $row !== false ? $this->mapRow($row) : null;
	}

	/**
	 * Existence check that reads the row itself and locks it until the caller's transaction ends.
	 *
	 * Unlike `findByIss()` it consults neither the file cache nor a transaction snapshot: a cached
	 * row survives a deletion made by another process (and the module's cache cannot always be
	 * invalidated across users), while a plain `SELECT` under `REPEATABLE READ` keeps returning the
	 * row this transaction saw first. A locking read always sees the latest committed state, which
	 * is what a writer about to stamp this issuer onto a catalog item needs.
	 *
	 * Whoever wants the lock to mean anything must call this inside a transaction and hold it until
	 * commit; without row-locking support the call degrades to a plain uncached existence check.
	 */
	public function lockByIss(string $iss): bool
	{
		if ($iss === '')
		{
			return false;
		}

		$connection = Application::getConnection();
		if (!in_array($connection->getType(), ['mysql', 'pgsql'], true))
		{
			// `FOR UPDATE` and the row-limit syntax below are the MySQL/PostgreSQL dialect. On the
			// rest the call degrades to an uncached existence check: no lock, but no broken SQL
			// either - and the callers already treat a missing lock as "not synchronised".
			return PairingTable::query()
				->setSelect(['ID'])
				->where('ISS', $iss)
				->setLimit(1)
				->fetch() !== false
			;
		}

		$helper = $connection->getSqlHelper();
		$sql = 'SELECT ' . $helper->quote('ID')
			. ' FROM ' . $helper->quote(PairingTable::getTableName())
			. ' WHERE ' . $helper->quote('ISS') . " = '" . $helper->forSql($iss) . "'"
			. ' LIMIT 1 FOR UPDATE'
		;

		return $connection->query($sql)->fetch() !== false;
	}

	/**
	 * @return list<Pairing>
	 */
	public function listAll(): array
	{
		$rows = PairingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->setOrder(['ISS' => 'ASC'])
			->fetchAll();

		return array_map(fn (array $row): Pairing => $this->mapRow($row), $rows);
	}

	public function listEndpointUrls(): array
	{
		$rows = PairingTable::query()
			->setSelect(['ENDPOINT_URL'])
			->setCacheTtl(self::CACHE_TTL_SECONDS)
			->fetchAll()
		;

		return array_column($rows, 'ENDPOINT_URL');
	}

	public function listExpired(?int $now = null): array
	{
		$threshold = DateTime::createFromTimestamp($now ?? time());
		$rows = PairingTable::query()
			->setSelect(self::SELECT_FIELDS)
			->where('EXPIRES_AT', '<=', $threshold)
			->fetchAll();

		return array_map(fn (array $row): Pairing => $this->mapRow($row), $rows);
	}

	public function hasAny(): bool
	{
		$row = PairingTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->setCacheTtl(self::CACHE_TTL_SECONDS)
			->fetch();

		return $row !== false;
	}

	public function upsert(Pairing $pairing): void
	{
		$existing = PairingTable::query()
			->setSelect(['ID'])
			->where('ISS', $pairing->iss)
			->setLimit(1)
			->fetch();

		$fields = [
			'ISS' => $pairing->iss,
			'PUBLIC_KEY' => $pairing->publicKey,
			'PORTAL_ID' => $pairing->portalId,
			'ENDPOINT_URL' => $pairing->endpointUrl,
			'FETCHED_AT' => DateTime::createFromTimestamp($pairing->fetchedAt),
			'EXPIRES_AT' => DateTime::createFromTimestamp($pairing->expiresAt),
			'PUBLIC_KEY_TTL' => $pairing->publicKeyTtl,
			'KEY_SOURCE' => $pairing->keySource->value,
		];

		if ($existing !== false)
		{
			PairingTable::update((int)$existing['ID'], $fields);
			$this->bustCache();

			return;
		}

		try
		{
			PairingTable::add($fields);
			$this->bustCache();
		}
		catch (DuplicateEntryException $e)
		{
		}
	}

	public function deleteByIss(string $iss): void
	{
		$this->deleteByIssKeepingCache($iss);
		$this->invalidateCache();
	}

	/**
	 * Deletes the row and leaves the cache alone, for callers running inside a transaction.
	 *
	 * Invalidating before the commit is worse than not invalidating at all: a reader that misses
	 * the cache in that window still sees the committed row, caches it again, and nothing
	 * invalidates it afterwards - so a revoked issuer would keep verifying tokens until the cached
	 * entry expires. Such callers must call `invalidateCache()` once the commit succeeded.
	 */
	public function deleteByIssKeepingCache(string $iss): void
	{
		$row = PairingTable::query()
			->setSelect(['ID'])
			->where('ISS', $iss)
			->setLimit(1)
			->fetch();

		if ($row !== false)
		{
			PairingTable::delete((int)$row['ID']);
		}
	}

	public function invalidateCache(): void
	{
		$this->bustCache();
	}

	public function deleteAll(): void
	{
		$rows = PairingTable::query()->setSelect(['ID'])->fetchAll();
		foreach ($rows as $row)
		{
			PairingTable::delete((int)$row['ID']);
		}
		if (!empty($rows))
		{
			$this->bustCache();
		}
	}

	public function updateSourceByIss(string $iss, PublicKeySource $source): void
	{
		$row = PairingTable::query()
			->setSelect(['ID'])
			->where('ISS', $iss)
			->setLimit(1)
			->fetch();

		if ($row !== false)
		{
			PairingTable::update((int)$row['ID'], ['KEY_SOURCE' => $source->value]);
			$this->bustCache();
		}
	}

	private function bustCache(): void
	{
		Cache::createInstance()->cleanDir(self::CACHE_DIR);
	}

	private function mapRow(array $row): Pairing
	{
		return new Pairing(
			iss: (string)$row['ISS'],
			publicKey: (string)$row['PUBLIC_KEY'],
			portalId: (string)$row['PORTAL_ID'],
			endpointUrl: (string)$row['ENDPOINT_URL'],
			fetchedAt: $row['FETCHED_AT'] instanceof DateTime ? $row['FETCHED_AT']->getTimestamp() : 0,
			expiresAt: $row['EXPIRES_AT'] instanceof DateTime ? $row['EXPIRES_AT']->getTimestamp() : 0,
			publicKeyTtl: (int)($row['PUBLIC_KEY_TTL'] ?? 0),
			keySource: PublicKeySource::tryFromOrDefault((string)($row['KEY_SOURCE'] ?? '')),
		);
	}
}
