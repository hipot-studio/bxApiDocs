<?php

namespace Bitrix\BIConnector\Integration\Iblock;

use Bitrix\BIConnector\DictionaryManager;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Diag\LoggerFactory;
use Throwable;

final class EventHandler
{
	// Section fields the department name dictionaries are built from.
	// Humanresources sync always passes them, so its sort and activity changes invalidate too.
	private const DEPARTMENT_NAME_FIELDS = ['NAME', 'IBLOCK_SECTION_ID'];

	public static function onAfterIBlockSectionAdd(array &$fields): void
	{
		if (empty($fields['RESULT']) || !self::isStructureIblock($fields))
		{
			return;
		}

		self::invalidateDepartmentNameCache($fields);
	}

	public static function onAfterIBlockSectionUpdate(array &$fields): void
	{
		if (
			empty($fields['RESULT'])
			|| !self::isStructureIblock($fields)
			|| empty(array_intersect(self::DEPARTMENT_NAME_FIELDS, array_keys($fields)))
		)
		{
			return;
		}

		self::invalidateDepartmentNameCache($fields);
	}

	public static function onAfterIBlockSectionDelete(array $section): void
	{
		if (!self::isStructureIblock($section))
		{
			return;
		}

		self::invalidateDepartmentNameCache($section);
	}

	private static function isStructureIblock(array $section): bool
	{
		$iblockId = (int)($section['IBLOCK_ID'] ?? 0);
		if ($iblockId <= 0)
		{
			return false;
		}

		return $iblockId === (int)Option::get('intranet', 'iblock_structure', 0);
	}

	private static function invalidateDepartmentNameCache(array $section): void
	{
		try
		{
			DictionaryManager::invalidateDepartmentNameCache();
		}
		catch (Throwable $exception)
		{
			try
			{
				(new LoggerFactory())
					->createDefault()
					?->error('Department name dictionary cache invalidation failed', [
						'sectionId' => (int)($section['ID'] ?? 0),
						'error' => $exception->getMessage(),
					])
				;
			}
			catch (Throwable)
			{
			}
		}
	}
}
