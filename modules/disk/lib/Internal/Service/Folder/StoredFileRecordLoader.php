<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Folder;

use Bitrix\Main\File\Internal\FileVersionTable;
use Bitrix\Main\FileTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\ScalarField;
use Bitrix\Main\ORM\Query\Join;

/**
 * Records of b_file in the shape CFile::GetFileArray() answers with, read in a batch: from the query of the
 * folder list, which joins b_file anyway, or by one query over the ids of the stored files.
 *
 * The record is kept as it is stored: the content type is not refined. A stored file which has a version in
 * b_file_version is left out, because CFile answers for it with the record of the version.
 *
 * @internal
 */
final class StoredFileRecordLoader
{
	public const PREFIX = 'STORED_FILE_';

	private const VERSION_REFERENCE = 'STORED_FILE_VERSION';
	private const VERSION_ID = 'STORED_VERSION_ID';

	/**
	 * Select of the query of the folder list: every field of the stored file with the prefix and the flag
	 * of a version beyond the prefix.
	 */
	public static function listingSelect(): array
	{
		return [
			self::PREFIX => 'FILE_CONTENT',
			self::VERSION_ID => self::VERSION_REFERENCE . '.VERSION_ID',
		];
	}

	/**
	 * Runtime reference of the query of the folder list to the version of the stored file.
	 */
	public static function listingRuntime(): array
	{
		return [
			self::versionReference('this.FILE_ID'),
		];
	}

	/**
	 * The record of the stored file carried by a row of the folder list, or null when the row has no stored
	 * file or the file has a version.
	 */
	public static function fromListingRow(array $row): ?array
	{
		if (empty($row[self::PREFIX . 'ID']) || !empty($row[self::VERSION_ID]))
		{
			return null;
		}

		$fields = [];
		foreach (self::fieldNames() as $name)
		{
			$fields[$name] = $row[self::PREFIX . $name] ?? null;
		}

		return self::shape($fields);
	}

	/**
	 * Tells whether a row of the folder list shows that its stored file has a version: such a file is not read
	 * by load() either, CFile answers for it.
	 */
	public static function hasVersionInListingRow(array $row): bool
	{
		return !empty($row[self::PREFIX . 'ID']) && !empty($row[self::VERSION_ID]);
	}

	/**
	 * Brings fields of b_file to the shape of CFile::GetFileArray(): strings, no version, no meta.
	 * The public url is left out: File::getFile() builds it when it is asked for.
	 */
	public static function shape(array $fields): array
	{
		$record = [];
		foreach ($fields as $name => $value)
		{
			$record[$name] = $value === null ? null : (string)$value;
		}

		$record['VERSION_ORIGINAL_ID'] = null;
		$record['META'] = '';

		return $record;
	}

	/**
	 * @param int[] $fileIds Ids of b_file.
	 * @return array<int, array> Records by the id of the stored file; files with a version are left out.
	 */
	public function load(array $fileIds): array
	{
		$fileIds = array_values(array_unique(array_filter(
			array_map('intval', $fileIds),
			static fn (int $id): bool => $id > 0,
		)));
		if (empty($fileIds))
		{
			return [];
		}

		$fieldNames = self::fieldNames();
		$rows = FileTable::query()
			->registerRuntimeField(self::versionReference('this.ID'))
			->setSelect([...$fieldNames, self::VERSION_ID => self::VERSION_REFERENCE . '.VERSION_ID'])
			->whereIn('ID', $fileIds)
			->fetchAll()
		;

		$records = [];
		foreach ($rows as $row)
		{
			if (!empty($row[self::VERSION_ID]))
			{
				continue;
			}

			$fields = [];
			foreach ($fieldNames as $name)
			{
				$fields[$name] = $row[$name] ?? null;
			}

			$records[(int)$row['ID']] = self::shape($fields);
		}

		return $records;
	}

	private static function versionReference(string $fileIdField): Reference
	{
		return (new Reference(
			self::VERSION_REFERENCE,
			FileVersionTable::class,
			Join::on($fileIdField, 'ref.ORIGINAL_ID'),
		))->configureJoinType(Join::TYPE_LEFT);
	}

	/**
	 * @return string[]
	 */
	private static function fieldNames(): array
	{
		return array_values(array_map(
			static fn (ScalarField $field): string => $field->getName(),
			FileTable::getEntity()->getScalarFields(),
		));
	}
}
