<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\Tracking\Internals\ExpensesPackTable;
use Bitrix\Crm\Tracking\Internals\SourceExpensesTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ExpensesDay;
use Bitrix\Crm\V2\Internal\Entity\Tracking\ExpensesPack;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

/**
 * Reads and writes the manual expenses of the sources in the tables legacy reads them from: a pack
 * with the totals and the comment and a row of every day of its period.
 *
 * The packs of the advertising integration share the table and are never read here.
 *
 * Every refusal of the storage is thrown, so the caller can roll its transaction back.
 *
 * @internal
 */
final class ExpensesRepository
{
	private const SELECT = [
		'ID', 'SOURCE_ID', 'DATE_FROM', 'DATE_TO', 'EXPENSES', 'CURRENCY_ID', 'ACTIONS', 'COMMENT', 'DATE_INSERT',
	];

	/**
	 * Stores a manual pack with its days; the id of the given pack is ignored. The days are stored without
	 * the comment, the way legacy stores them, by one query: the table of the days has no handlers of its events.
	 * The caller wraps the call in a transaction.
	 *
	 * @param list<ExpensesDay> $days
	 * @throws SystemException
	 */
	public function create(ExpensesPack $pack, array $days): int
	{
		$fields = [
			'SOURCE_ID' => $pack->sourceId,
			'TYPE_ID' => ExpensesPackTable::TYPE_MANUAL,
			'DATE_FROM' => clone $pack->dateFrom,
			'DATE_TO' => clone $pack->dateTo,
			'ACTIONS' => $pack->actions,
			'EXPENSES' => $pack->amount,
			'CURRENCY_ID' => $pack->currencyId,
			'COMMENT' => $pack->comment,
		];
		if ($pack->createdTime !== null)
		{
			$fields['DATE_INSERT'] = clone $pack->createdTime;
		}

		$result = ExpensesPackTable::add($fields);
		self::assertSuccess($result, 'add the expenses pack');
		$packId = (int)$result->getId();

		$rows = [];
		foreach ($days as $day)
		{
			$rows[] = [
				'PACK_ID' => $packId,
				'SOURCE_ID' => $pack->sourceId,
				'TYPE_ID' => SourceExpensesTable::TYPE_MANUAL,
				'DATE_STAT' => clone $day->date,
				'ACTIONS' => $day->actions,
				'EXPENSES' => $day->amount,
				'CURRENCY_ID' => $pack->currencyId,
			];
		}
		if ($rows !== [])
		{
			self::assertSuccess(SourceExpensesTable::addMulti($rows, true), 'add the expenses of the days');
		}

		return $packId;
	}

	/**
	 * Returns a page of the manual packs of the source, the latest first.
	 *
	 * @return list<ExpensesPack>
	 */
	public function listManual(int $sourceId, int $limit, int $offset): array
	{
		$rows =
			ExpensesPackTable::query()
				->setSelect(self::SELECT)
				->where('SOURCE_ID', $sourceId)
				->where('TYPE_ID', ExpensesPackTable::TYPE_MANUAL)
				->setOrder(['ID' => 'DESC'])
				->setLimit($limit)
				->setOffset($offset)
				->fetchAll()
		;

		return array_map(self::mapPack(...), $rows);
	}

	/**
	 * Returns the manual pack; a pack of the advertising integration is not found.
	 */
	public function getManual(int $packId): ?ExpensesPack
	{
		if ($packId <= 0)
		{
			return null;
		}

		$row =
			ExpensesPackTable::query()
				->setSelect(self::SELECT)
				->where('ID', $packId)
				->where('TYPE_ID', ExpensesPackTable::TYPE_MANUAL)
				->setLimit(1)
				->fetch()
		;

		return $row ? self::mapPack($row) : null;
	}

	/**
	 * Deletes the pack with its days. The days are deleted by one query first, so the handler of the table
	 * of the packs, which deletes them one by one ({@see ExpensesPackTable::onDelete()}), finds none left.
	 * The caller wraps the call in a transaction.
	 *
	 * @throws SystemException
	 */
	public function delete(int $packId): void
	{
		self::deleteDays($packId);
		self::assertSuccess(ExpensesPackTable::delete($packId), 'delete the expenses pack');
	}

	/**
	 * Deletes the days of the pack the way SourceExpensesTable::delete() deletes them one by one: the table
	 * has no handlers of its events, so only its ORM cache is cleaned.
	 *
	 * @throws SystemException
	 */
	private static function deleteDays(int $packId): void
	{
		$entity = SourceExpensesTable::getEntity();
		$connection = $entity->getConnection();
		$where = Query::buildFilterSql($entity, Query::filter()->where('PACK_ID', $packId));

		$connection->queryExecute(
			'DELETE FROM ' . $connection->getSqlHelper()->quote(SourceExpensesTable::getTableName())
			. ' WHERE ' . $where,
		);
		if ($connection->getAffectedRowsCount() > 0)
		{
			SourceExpensesTable::cleanCache();
		}
	}

	private static function mapPack(array $row): ExpensesPack
	{
		$comment = $row['COMMENT'];

		return new ExpensesPack(
			id: (int)$row['ID'],
			sourceId: (int)$row['SOURCE_ID'],
			dateFrom: self::toDate($row['DATE_FROM']),
			dateTo: self::toDate($row['DATE_TO']),
			amount: (float)$row['EXPENSES'],
			currencyId: (string)$row['CURRENCY_ID'],
			actions: (int)$row['ACTIONS'],
			comment: $comment === null || $comment === '' ? null : (string)$comment,
			createdTime: $row['DATE_INSERT'] instanceof DateTime ? $row['DATE_INSERT'] : null,
		);
	}

	private static function toDate(mixed $value): Date
	{
		return $value instanceof Date ? $value : new Date();
	}

	/**
	 * @throws SystemException
	 */
	private static function assertSuccess(Result $result, string $action): void
	{
		if (!$result->isSuccess())
		{
			throw new SystemException(
				sprintf('Could not %s: %s', $action, implode('; ', $result->getErrorMessages())),
			);
		}
	}
}
