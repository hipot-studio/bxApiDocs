<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Requisite;

use Bitrix\Crm\Requisite\LinkTable;
use Bitrix\Crm\V2\Internal\Service\Requisite\EntityLinkService;
use Bitrix\Main;

/**
 * Writes one pair of the link row of a CRM entity, and nothing else.
 *
 * The row holds two pairs of identifiers - the requisite of the client and the requisite of the
 * seller - and the general write {@see LinkTable::upsert()} replaces all four at once. A caller who
 * means to change only one of them would therefore erase the other, so each pair gets a write of its
 * own: a single merge whose update part names the columns of that pair alone. The columns of the
 * other pair appear in the insert part only, where there is no stored row to preserve, and are
 * written as zero there.
 *
 * Both columns of a pair are always named: the pair is set as a whole, so a zero bank requisite is an
 * order to clear the bank requisite rather than a wish to keep the stored one. A zero requisite
 * clears the whole pair and leaves the row in place; for an entity with no row at all it creates a
 * row of zeroes, which is what {@see EntityLinkService::register()} does today as well.
 *
 * There is no way to write both pairs at once here: that is what the general write is for.
 *
 * No transaction is taken - one merge is atomic by itself.
 *
 * The read cache of {@see EntityLinkService} is not touched: it belongs to the service, and a write
 * that goes past the service is invisible to it until its owner is told. Telling it is the caller's,
 * as {@see \Bitrix\Crm\V2\Internal\Service\Requisite\ClientRequisiteService::set()} and
 * {@see \Bitrix\Crm\V2\Internal\Service\Requisite\MyCompanyRequisiteService::set()} do - the class
 * writes the row and knows nothing above it.
 *
 * @internal
 */
final class EntityLinkRepository
{
	private const ERROR_GREATER_THAN_ZERO = 'Must be greater than zero';
	private const ERROR_NEGATIVE = 'Must not be negative';

	/**
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 */
	public function setClientPair(
		int $entityTypeId,
		int $entityId,
		int $requisiteId,
		int $bankRequisiteId,
	): void
	{
		$this->setPair(
			$entityTypeId,
			$entityId,
			$requisiteId,
			$bankRequisiteId,
			'REQUISITE_ID',
			'BANK_DETAIL_ID',
		);
	}

	/**
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 */
	public function setMyCompanyPair(
		int $entityTypeId,
		int $entityId,
		int $requisiteId,
		int $bankRequisiteId,
	): void
	{
		$this->setPair(
			$entityTypeId,
			$entityId,
			$requisiteId,
			$bankRequisiteId,
			'MC_REQUISITE_ID',
			'MC_BANK_DETAIL_ID',
		);
	}

	/**
	 * @throws Main\ArgumentException
	 * @throws Main\DB\SqlQueryException
	 */
	private function setPair(
		int $entityTypeId,
		int $entityId,
		int $requisiteId,
		int $bankRequisiteId,
		string $requisiteColumn,
		string $bankRequisiteColumn,
	): void
	{
		$this->assertPositive($entityTypeId, 'entityTypeId');
		$this->assertPositive($entityId, 'entityId');
		$this->assertNotNegative($requisiteId, 'requisiteId');
		$this->assertNotNegative($bankRequisiteId, 'bankRequisiteId');

		// the whole update part: the columns of the other pair are left with what the row holds
		$pair = [
			$requisiteColumn => $requisiteId,
			$bankRequisiteColumn => $bankRequisiteId,
		];

		$connection = Main\Application::getConnection();
		$merge = $connection->getSqlHelper()->prepareMerge(
			LinkTable::getTableName(),
			[
				'ENTITY_TYPE_ID',
				'ENTITY_ID',
			],
			array_merge(
				[
					'ENTITY_TYPE_ID' => $entityTypeId,
					'ENTITY_ID' => $entityId,
					'REQUISITE_ID' => 0,
					'BANK_DETAIL_ID' => 0,
					'MC_REQUISITE_ID' => 0,
					'MC_BANK_DETAIL_ID' => 0,
				],
				$pair,
			),
			$pair,
		);
		$connection->queryExecute($merge[0]);
	}

	/** @throws Main\ArgumentException */
	private function assertPositive(int $value, string $parameter): void
	{
		if ($value <= 0)
		{
			throw new Main\ArgumentException(self::ERROR_GREATER_THAN_ZERO, $parameter);
		}
	}

	/** @throws Main\ArgumentException */
	private function assertNotNegative(int $value, string $parameter): void
	{
		if ($value < 0)
		{
			throw new Main\ArgumentException(self::ERROR_NEGATIVE, $parameter);
		}
	}
}
