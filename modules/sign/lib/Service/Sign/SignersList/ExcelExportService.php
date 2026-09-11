<?php

namespace Bitrix\Sign\Service\Sign\SignersList;

use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Util\Request\File;

final class ExcelExportService
{
	public const STATUS_READY = 'ready';
	public const STATUS_EMPTY = 'empty';
	public const STATUS_LIMIT_EXCEEDED = 'limitExceeded';

	private const DEFAULT_FILE_NAME = 'sign-b2e-signers';

	public function getStatus(int $rowCount, int $limit): string
	{
		if ($rowCount === 0)
		{
			return self::STATUS_EMPTY;
		}

		return $rowCount > $limit
			? self::STATUS_LIMIT_EXCEEDED
			: self::STATUS_READY
		;
	}

	public function getVisibleColumns(array $columns, array $visibleColumnIds): array
	{
		$defaultColumns = array_values(array_filter(
			$columns,
			static fn(array $column): bool => (bool)($column['default'] ?? false),
		));

		if ($visibleColumnIds === [])
		{
			return $defaultColumns;
		}

		$columnsById = [];
		foreach ($columns as $column)
		{
			$columnsById[$column['id']] = $column;
		}

		$visibleColumns = [];
		foreach ($visibleColumnIds as $columnId)
		{
			if (is_string($columnId) && isset($columnsById[$columnId]))
			{
				$visibleColumns[] = $columnsById[$columnId];
			}
		}

		return $visibleColumns !== [] ? $visibleColumns : $defaultColumns;
	}

	public function getFileName(?string $title, ?DateTime $dateTime = null): string
	{
		$title = trim((string)$title);
		if ($title === '')
		{
			$title = self::DEFAULT_FILE_NAME;
		}

		$dateTime ??= new DateTime();
		$dateTime->toUserTime();
		$fileName = sprintf('%s %s.xls', $title, $dateTime->format('Y-m-d H-i'));

		return File::sanitizeFilename($fileName) ?? self::DEFAULT_FILE_NAME . '.xls';
	}
}
