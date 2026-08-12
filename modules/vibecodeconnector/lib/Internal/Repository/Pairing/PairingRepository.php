<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Pairing;

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
		$row = PairingTable::query()
			->setSelect(['ID'])
			->where('ISS', $iss)
			->setLimit(1)
			->fetch();

		if ($row !== false)
		{
			PairingTable::delete((int)$row['ID']);
			$this->bustCache();
		}
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
