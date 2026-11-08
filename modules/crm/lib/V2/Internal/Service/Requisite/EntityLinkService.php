<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\Cache\RatedCache;
use Bitrix\Crm\Requisite\LinkTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use CCrmOwnerType;

/**
 * The link row of a CRM entity: which requisite of the client, and which requisite of the seller,
 * is chosen in this deal, invoice, quote or smart process.
 *
 * The row is keyed by the entity that owns it and holds four identifiers, where zero means
 * "nothing is chosen". Neither the entity nor the identifiers are checked against their tables:
 * dangling rows are a real state of the storage, and the service keeps serving them. The type of
 * the entity is not checked either - {@see self::getAvailableEntityTypeIds()} tells which types a
 * caller may address, but reading and writing serve any positive type, orders included.
 *
 * The read cache is shared by the whole process and is the reason the service is a singleton:
 * {@see self::getByEntity()} caches the absence of a row as well, so a row written past the class
 * stays invisible until {@see self::clearReadCache()}. Writes made through the service reset it;
 * writes made anywhere else do not.
 *
 * @internal
 */
final class EntityLinkService
{
	private const ERROR_GREATER_THAN_ZERO = 'Must be greater than zero';

	private static ?self $instance = null;

	private ?RatedCache $readCache = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		return self::$instance ??= new self();
	}

	/**
	 * @return array<string, string>|null the four value columns as the driver gives them, or null
	 *                                    when the key is unreadable or the row is absent
	 */
	public function getByEntity(int $entityTypeId, int $entityId): ?array
	{
		if (!CCrmOwnerType::IsDefined($entityTypeId) || $entityId <= 0)
		{
			return null;
		}

		$cache = $this->readCache ??= new RatedCache();
		$cacheKey = $entityTypeId . '_' . $entityId;
		$cached = $cache->get($cacheKey);
		if ($cached !== null)
		{
			// the absence of a row is cached as an empty array, and is answered the way the first
			// read answers it
			return empty($cached) ? null : $cached;
		}

		$row = LinkTable::getList([
			'filter' => ['=ENTITY_TYPE_ID' => $entityTypeId, '=ENTITY_ID' => $entityId],
			'select' => ['REQUISITE_ID', 'BANK_DETAIL_ID', 'MC_REQUISITE_ID', 'MC_BANK_DETAIL_ID'],
			'limit' => 1,
		])->fetch();

		$row = is_array($row) ? $row : [];
		$cache->set($cacheKey, $row);

		return !empty($row) ? $row : null;
	}

	/** @param array<string, mixed> $parameters */
	public function getList(array $parameters): Main\DB\Result
	{
		return LinkTable::getList($parameters);
	}

	/**
	 * Writes the whole row: every identifier not named by the caller is written as zero.
	 *
	 * @throws Main\ArgumentException
	 */
	public function register(
		int $entityTypeId,
		int $entityId,
		int $requisiteId = 0,
		int $bankDetailId = 0,
		int $mcRequisiteId = 0,
		int $mcBankDetailId = 0,
	): void
	{
		$this->assertPositive($entityTypeId, 'entityTypeId');
		$this->assertPositive($entityId, 'entityId');
		$this->assertNotNegative($requisiteId, 'requisiteId');
		$this->assertNotNegative($bankDetailId, 'bankDetailId');
		$this->assertNotNegative($mcRequisiteId, 'mcRequisiteId');
		$this->assertNotNegative($mcBankDetailId, 'mcBankDetailId');

		LinkTable::upsert([
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
			'REQUISITE_ID' => $requisiteId,
			'BANK_DETAIL_ID' => $bankDetailId,
			'MC_REQUISITE_ID' => $mcRequisiteId,
			'MC_BANK_DETAIL_ID' => $mcBankDetailId,
		]);

		$this->clearReadCache();
	}

	/**
	 * Removes the row of the entity. An entity without a row is not an error.
	 *
	 * @throws Main\ArgumentException
	 */
	public function unregister(int $entityTypeId, int $entityId): void
	{
		$this->assertPositive($entityTypeId, 'entityTypeId');
		$this->assertPositive($entityId, 'entityId');

		LinkTable::delete([
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
		]);

		$this->clearReadCache();
	}

	/**
	 * The entity types a link row may be addressed by. Smart invoices, smart documents and B2E
	 * documents arrive with the map of dynamic types, so the list depends on the portal and is
	 * built at every call.
	 *
	 * @return array<int, int> entityTypeId => entityTypeId
	 */
	public function getAvailableEntityTypeIds(): array
	{
		$types = [
			CCrmOwnerType::Deal => CCrmOwnerType::Deal,
			CCrmOwnerType::Invoice => CCrmOwnerType::Invoice,
			CCrmOwnerType::Quote => CCrmOwnerType::Quote,
		];
		$dynamicTypesMap = Container::getInstance()->getDynamicTypesMap()->load([
			'isLoadCategories' => false,
			'isLoadStages' => false,
		]);

		foreach ($dynamicTypesMap->getTypesCollection() as $type)
		{
			$types[$type->getEntityTypeId()] = $type->getEntityTypeId();
		}

		return $types;
	}

	public function clearReadCache(): void
	{
		$this->readCache?->clear();
	}

	/** @throws Main\ArgumentException */
	private function assertPositive(int $value, string $parameter): void
	{
		if ($value <= 0)
		{
			throw new Main\ArgumentException(self::ERROR_GREATER_THAN_ZERO, $parameter);
		}
	}

	/** The message names zero as the bound although zero is accepted here: observed by the old REST. */
	private function assertNotNegative(int $value, string $parameter): void
	{
		if ($value < 0)
		{
			throw new Main\ArgumentException(self::ERROR_GREATER_THAN_ZERO, $parameter);
		}
	}
}
