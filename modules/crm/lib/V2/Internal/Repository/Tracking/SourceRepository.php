<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Tracking;

use Bitrix\Crm\Tracking\Internals\SourceFieldTable;
use Bitrix\Crm\Tracking\Internals\SourceTable;
use Bitrix\Crm\V2\Internal\Entity\Tracking\SourceRecord;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * Reads the stored sources and their fields from the tables themselves.
 *
 * The legacy readers keep the sources and the fields in the ORM cache for an hour and write the email
 * marketing source while reading; here nothing is cached and nothing is written, so a change is seen at once.
 *
 * @internal
 */
final class SourceRepository
{
	private const SELECT = ['ID', 'CODE', 'NAME', 'ICON_COLOR', 'ACTIVE'];
	private const ACTIVE_SOURCE = 'ACTIVE_SOURCE';

	/**
	 * Returns whether the source exists and is not archived.
	 *
	 * Reads the table itself: the legacy list of sources is cached by ORM and filtered by the tariff, so
	 * it can still hold a source that was archived a moment ago.
	 */
	public function isActive(int $sourceId): bool
	{
		if ($sourceId <= 0)
		{
			return false;
		}

		$row =
			SourceTable::query()
				->setSelect(['ID'])
				->where('ID', $sourceId)
				->where('ACTIVE', 'Y')
				->setLimit(1)
				->fetch()
		;

		return $row !== false;
	}

	/**
	 * Returns whether the source exists, archived or not.
	 */
	public function exists(int $sourceId): bool
	{
		if ($sourceId <= 0)
		{
			return false;
		}

		$row =
			SourceTable::query()
				->setSelect(['ID'])
				->where('ID', $sourceId)
				->setLimit(1)
				->fetch()
		;

		return $row !== false;
	}

	/**
	 * Returns the stored sources of the activity whose code the table holds equal to one of the codes, in the
	 * order of ID, without their fields: {@see withFields()} reads them. The table compares the codes by its
	 * collation, so a code that differs in case only is returned too, as stored.
	 *
	 * @param list<string> $codes
	 * @return list<SourceRecord>
	 */
	public function listByCodesWithoutFields(array $codes, bool $isActive): array
	{
		if ($codes === [])
		{
			return [];
		}

		$rows = $this->queryByActivity($isActive)->whereIn('CODE', $codes)->fetchAll();

		return $this->toRecords($rows, []);
	}

	/**
	 * Returns a page, in the order of ID, of the stored sources of the activity, the given ones excepted,
	 * without their fields: {@see withFields()} reads them.
	 *
	 * @param list<int> $exceptIds
	 * @return list<SourceRecord>
	 */
	public function listPageWithoutFields(bool $isActive, array $exceptIds, int $limit, int $offset): array
	{
		if ($limit <= 0 || $offset < 0)
		{
			return [];
		}

		$query = $this->queryByActivity($isActive)->setLimit($limit)->setOffset($offset);
		if ($exceptIds !== [])
		{
			$query->whereNotIn('ID', $exceptIds);
		}

		return $this->toRecords($query->fetchAll(), []);
	}

	/**
	 * Returns the records, in the same order, with their stored fields read with one query.
	 *
	 * @param list<SourceRecord> $records
	 * @return list<SourceRecord>
	 */
	public function withFields(array $records): array
	{
		if ($records === [])
		{
			return [];
		}

		$fields = $this->loadFields(array_map(static fn(SourceRecord $record): int => $record->id, $records));

		return array_map(
			static fn(SourceRecord $record): SourceRecord => new SourceRecord(
				id: $record->id,
				code: $record->code,
				name: $record->name,
				color: $record->color,
				isActive: $record->isActive,
				fields: $fields[$record->id] ?? [],
			),
			$records,
		);
	}

	public function getRecord(int $sourceId): ?SourceRecord
	{
		if ($sourceId <= 0)
		{
			return null;
		}

		$row =
			SourceTable::query()
				->setSelect(self::SELECT)
				->where('ID', $sourceId)
				->setLimit(1)
				->fetch()
		;
		if (!$row)
		{
			return null;
		}

		return $this->toRecords([$row], $this->loadFields([$sourceId]))[0];
	}

	/**
	 * Returns the stored sources of the code, archived ones included, in the order of ID: the UI can save
	 * more than one record of a code.
	 *
	 * @param string $code empty for the own sources
	 * @return list<SourceRecord>
	 */
	public function listRecordsByCode(string $code): array
	{
		$rows =
			SourceTable::query()
				->setSelect(self::SELECT)
				->where('CODE', $code)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;
		if (!$rows)
		{
			return [];
		}

		return $this->toRecords($rows, $this->loadFields(array_map('intval', array_column($rows, 'ID'))));
	}

	/**
	 * Returns whether any active source has a value of the field, as a phone for the calls or an email
	 * for the mail.
	 *
	 * One query of the first value of the code joined to its active source: no list of the sources is read
	 * or sent back.
	 *
	 * @param string $fieldCode one of the SourceFieldTable::FIELD_* codes
	 */
	public function hasActiveWithField(string $fieldCode): bool
	{
		$row =
			SourceFieldTable::query()
				->setSelect(['ID'])
				->registerRuntimeField(
					new Reference(
						self::ACTIVE_SOURCE,
						SourceTable::class,
						Join::on('this.SOURCE_ID', 'ref.ID'),
						['join_type' => Join::TYPE_INNER],
					),
				)
				->where('CODE', $fieldCode)
				->where(self::ACTIVE_SOURCE . '.ACTIVE', 'Y')
				->setLimit(1)
				->fetch()
		;

		return $row !== false;
	}

	/**
	 * The stored sources of the activity in the order of ID; any mark but `Y` is archived, as the records read it.
	 */
	private function queryByActivity(bool $isActive): Query
	{
		return
			SourceTable::query()
				->setSelect(self::SELECT)
				->where('ACTIVE', $isActive ? '=' : '!=', 'Y')
				->setOrder(['ID' => 'ASC'])
		;
	}

	/**
	 * @param list<int> $sourceIds
	 * @return array<int, array<string, list<string>>> source ID => field code => values in the order stored
	 */
	private function loadFields(array $sourceIds): array
	{
		$rows =
			SourceFieldTable::query()
				->setSelect(['SOURCE_ID', 'CODE', 'VALUE'])
				->whereIn('SOURCE_ID', $sourceIds)
				->setOrder(['ID' => 'ASC'])
				->fetchAll()
		;

		$fields = [];
		foreach ($rows as $row)
		{
			$fields[(int)$row['SOURCE_ID']][(string)$row['CODE']][] = (string)$row['VALUE'];
		}

		return $fields;
	}

	/**
	 * @param array<int, array<string, list<string>>> $fields
	 * @return list<SourceRecord>
	 */
	private function toRecords(array $rows, array $fields): array
	{
		$records = [];
		foreach ($rows as $row)
		{
			$id = (int)$row['ID'];
			$records[] = new SourceRecord(
				id: $id,
				code: (string)$row['CODE'],
				name: (string)$row['NAME'],
				color: (string)$row['ICON_COLOR'],
				isActive: $row['ACTIVE'] === 'Y' || $row['ACTIVE'] === true,
				fields: $fields[$id] ?? [],
			);
		}

		return $records;
	}
}
