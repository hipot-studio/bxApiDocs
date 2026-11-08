<?php

namespace Bitrix\Catalog\Update;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Update\Stepper;

/**
 * Removes the options the store amount grids used to keep in b_user_option under per product names.
 *
 * @package Bitrix\Catalog\Update
 */
final class StoreAmountGridOptionsCleaner extends Stepper
{
	protected static $moduleId = 'catalog';

	private const PORTION_OPTION = 'store_amount_grid_options_cleanup_limit';
	private const DEFAULT_PORTION = 500;

	/** A step is never split, so the cap bounds one agent hit whatever an administrator puts into the option. */
	private const MAX_PORTION = 10000;

	private const CATEGORIES = [
		'main.interface.grid',
		'main.interface.grid.common',
		'main.ui.filter',
		'main.ui.filter.common',
	];

	/** Only narrows the scan: an underscore matches any character here, the allowlist decides. */
	private const NAME_MASK = 'productcard_store_amount_%';

	/**
	 * The prefix also matches the shared grid ids, so a legacy name must end with a product id.
	 * \z and not $: $ also matches before a trailing line break.
	 */
	private const LEGACY_NAME_PATTERNS = [
		'/^productcard_store_amount_\d+\z/',
		'/^productcard_store_amount_details_\d+\z/',
	];

	public function execute(array &$option): bool
	{
		$portion = self::getPortion();
		$cursor = self::readCursor($option);

		if (!isset($option['count']))
		{
			$option['count'] = self::countRowsToScan($cursor);
			$option['steps'] = 0;
		}

		$rows = Application::getConnection()->query(self::buildSql($cursor), $portion)->fetchAll();

		$legacyRows = array_filter(
			$rows,
			static fn(array $row): bool => self::isLegacyName((string)$row['NAME']),
		);
		self::deleteOptions($legacyRows);

		if ($rows !== [])
		{
			$option['cursor'] = self::makeCursor($rows[array_key_last($rows)]);
		}
		$option['steps'] = (int)($option['steps'] ?? 0) + count($rows);
		$option['count'] = max((int)$option['count'], (int)$option['steps']);

		return count($rows) < $portion ? self::FINISH_EXECUTION : self::CONTINUE_EXECUTION;
	}

	public static function getTitle(): string
	{
		return (string)Loc::getMessage('CATALOG_STORE_AMOUNT_GRID_OPTIONS_CLEANER_TITLE');
	}

	/**
	 * The order of the unique index (USER_ID, CATEGORY, NAME): the cache segment is USER_ID + CATEGORY, so the
	 * rows of a segment are adjacent and a portion touches as few segments as possible.
	 */
	private static function buildSql(?array $cursor): string
	{
		return 'SELECT ID, USER_ID, CATEGORY, NAME, COMMON FROM b_user_option'
			. ' WHERE ' . self::buildScanCondition($cursor)
			. ' ORDER BY USER_ID ASC, CATEGORY ASC, NAME ASC'
		;
	}

	private static function countRowsToScan(?array $cursor): int
	{
		return (int)Application::getConnection()->queryScalar(
			'SELECT COUNT(1) FROM b_user_option WHERE ' . self::buildScanCondition($cursor),
		);
	}

	private static function buildScanCondition(?array $cursor): string
	{
		$helper = Application::getConnection()->getSqlHelper();

		$categories = array_map(
			static fn(string $category): string => $helper->convertToDbString($category),
			self::CATEGORIES,
		);
		$condition =
			'CATEGORY IN (' . implode(', ', $categories) . ')'
			. ' AND NAME LIKE ' . $helper->convertToDbString(self::NAME_MASK)
		;

		if ($cursor === null)
		{
			return $condition;
		}

		// the leading USER_ID bound lets PostgreSQL start the index scan at the cursor
		$afterCursor = new SqlExpression(
			'USER_ID >= ?i AND (USER_ID > ?i OR (USER_ID = ?i AND (CATEGORY > ?s OR (CATEGORY = ?s AND NAME > ?s))))',
			$cursor['userId'],
			$cursor['userId'],
			$cursor['userId'],
			$cursor['category'],
			$cursor['category'],
			$cursor['name'],
		);

		return $condition . ' AND ' . $afterCursor->compile();
	}

	/**
	 * @return array{userId: int, category: string, name: string}|null the key of the last scanned row
	 */
	private static function readCursor(array $option): ?array
	{
		$cursor = $option['cursor'] ?? null;
		if (!is_array($cursor))
		{
			return null;
		}

		return [
			'userId' => (int)($cursor['userId'] ?? 0),
			'category' => (string)($cursor['category'] ?? ''),
			'name' => (string)($cursor['name'] ?? ''),
		];
	}

	private static function makeCursor(array $row): array
	{
		return [
			'userId' => (int)$row['USER_ID'],
			'category' => (string)$row['CATEGORY'],
			'name' => (string)$row['NAME'],
		];
	}

	private static function isLegacyName(string $name): bool
	{
		foreach (self::LEGACY_NAME_PATTERNS as $pattern)
		{
			if (preg_match($pattern, $name) === 1)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Deletes the rows with one DELETE, except a row per cache segment: that one goes through the API and drops
	 * the cache of its segment.
	 *
	 * @param array[] $rows
	 */
	private static function deleteOptions(array $rows): void
	{
		if ($rows === [])
		{
			return;
		}

		$segmentRows = self::pickRowPerCacheSegment($rows);
		$segmentIds = array_map(static fn(array $row): int => (int)$row['ID'], $segmentRows);
		$restIds = array_diff(
			array_map(static fn(array $row): int => (int)$row['ID'], $rows),
			$segmentIds,
		);

		self::deleteRowsByIds($restIds);

		// the cache goes last: a parallel hit must not cache back a row that is still being deleted
		foreach ($segmentRows as $row)
		{
			self::deleteOptionThroughApi($row);
		}
	}

	/**
	 * Only \CUserOptions::DeleteOption() drops the managed and the static cache of the options: one segment for
	 * a user row, all of them for a common row (main/classes/general/user_options.php:295-304).
	 *
	 * @param array[] $rows
	 * @return array[] a row per segment, or a single common row that covers all of them
	 */
	private static function pickRowPerCacheSegment(array $rows): array
	{
		$commonRow = null;
		$userRows = [];

		foreach ($rows as $row)
		{
			if ($row['COMMON'] === 'Y')
			{
				$commonRow ??= $row;

				continue;
			}

			$segment = (int)$row['USER_ID'] . ':' . mb_strtolower((string)$row['CATEGORY']);
			$userRows[$segment] ??= $row;
		}

		return $commonRow === null ? array_values($userRows) : [$commonRow];
	}

	/**
	 * By id: the LIKE of the scan is wider than the allowlist.
	 *
	 * @param int[] $ids
	 */
	private static function deleteRowsByIds(array $ids): void
	{
		if ($ids === [])
		{
			return;
		}

		Application::getConnection()->queryExecute(
			'DELETE FROM b_user_option WHERE ID IN (' . implode(', ', $ids) . ')',
		);
	}

	/**
	 * The user id is explicit: without it \CUserOptions::DeleteOption() takes the user the agent runs under.
	 */
	private static function deleteOptionThroughApi(array $row): void
	{
		\CUserOptions::DeleteOption(
			$row['CATEGORY'],
			$row['NAME'],
			$row['COMMON'] === 'Y',
			(int)$row['USER_ID'],
		);
	}

	private static function getPortion(): int
	{
		$portion = (int)Option::get(self::$moduleId, self::PORTION_OPTION, self::DEFAULT_PORTION);

		if ($portion <= 0)
		{
			return self::DEFAULT_PORTION;
		}

		return min($portion, self::MAX_PORTION);
	}
}
