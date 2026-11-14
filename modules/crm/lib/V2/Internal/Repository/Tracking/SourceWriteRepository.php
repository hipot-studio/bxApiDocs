<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\Tracking\Internals\SourceFieldTable;
use Bitrix\Crm\Tracking\Internals\SourceTable;
use Bitrix\Main\ORM\Data\Result;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\SystemException;

/**
 * Writes the sources and their fields to the tables legacy reads them from.
 *
 * Only the table layer is used, so every write cleans the ORM cache of the legacy readers by itself; the one
 * delete written past it by a filter of the table cleans that cache explicitly.
 * The tags and the advertising account of a source belong to the advertising integration and are
 * never touched here.
 *
 * Every refusal of the storage is thrown, so the caller can roll its transaction back.
 *
 * @internal
 */
final class SourceWriteRepository
{
	/**
	 * @param string $code empty for an own source
	 * @throws SystemException
	 */
	public function create(string $code, string $name, string $color, int $userId): int
	{
		$result = SourceTable::add([
			'CODE' => $code,
			'NAME' => $name,
			'ICON_COLOR' => $color,
			'ACTIVE' => 'Y',
			'CREATED_BY_ID' => $userId,
			'UPDATED_BY_ID' => $userId,
		]);
		self::assertSuccess($result, 'add the source');

		return (int)$result->getId();
	}

	/**
	 * @param array{name?: string, color?: string} $changes only the given values are written
	 * @throws SystemException
	 */
	public function update(int $sourceId, array $changes, int $userId): void
	{
		$fields = ['UPDATED_BY_ID' => $userId];
		if (array_key_exists('name', $changes))
		{
			$fields['NAME'] = $changes['name'];
		}
		if (array_key_exists('color', $changes))
		{
			$fields['ICON_COLOR'] = $changes['color'];
		}

		self::assertSuccess(SourceTable::update($sourceId, $fields), 'update the source');
	}

	/**
	 * @throws SystemException
	 */
	public function setActive(int $sourceId, bool $isActive): void
	{
		self::assertSuccess(
			SourceTable::update($sourceId, ['ACTIVE' => $isActive ? 'Y' : 'N']),
			$isActive ? 'restore the source' : 'archive the source',
		);
	}

	/**
	 * Replaces the stored values of the field with the given ones, in their order.
	 *
	 * Unlike SourceFieldTable::setSourceField(), every given value is stored, "0" included: the caller
	 * passes the values it has validated. The old values are deleted and the new ones inserted by one query
	 * each; the table has no handlers of its events.
	 *
	 * @param string $fieldCode one of the SourceFieldTable::FIELD_* codes
	 * @param list<string> $values
	 * @throws SystemException
	 */
	public function replaceField(int $sourceId, string $fieldCode, array $values): void
	{
		self::deleteField($sourceId, $fieldCode);

		if ($values === [])
		{
			return;
		}

		$rows = [];
		foreach ($values as $value)
		{
			$rows[] = [
				'SOURCE_ID' => $sourceId,
				'CODE' => $fieldCode,
				'VALUE' => $value,
			];
		}
		self::assertSuccess(SourceFieldTable::addMulti($rows, true), 'add the field of the source');
	}

	/**
	 * Cleans the ORM cache of the legacy readers once more: a reader between the write and the commit
	 * may have cached the previous state.
	 */
	public function cleanCache(): void
	{
		SourceTable::cleanCache();
		SourceFieldTable::cleanCache();
	}

	/**
	 * Deletes the stored values of the field the way SourceFieldTable::delete() deletes them one by one: the
	 * table has no handlers of its events, so only its ORM cache is cleaned.
	 *
	 * @throws SystemException
	 */
	private static function deleteField(int $sourceId, string $fieldCode): void
	{
		$entity = SourceFieldTable::getEntity();
		$connection = $entity->getConnection();
		$where = Query::buildFilterSql(
			$entity,
			Query::filter()
				->where('SOURCE_ID', $sourceId)
				->where('CODE', $fieldCode),
		);

		$connection->queryExecute(
			'DELETE FROM ' . $connection->getSqlHelper()->quote(SourceFieldTable::getTableName())
			. ' WHERE ' . $where,
		);
		if ($connection->getAffectedRowsCount() > 0)
		{
			SourceFieldTable::cleanCache();
		}
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
