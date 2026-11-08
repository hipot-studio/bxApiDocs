<?php

namespace Bitrix\BIConnector\Access\Service;

use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\Manager;

/**
 * The only read point of the table catalog for the rights subsystem.
 *
 * The catalog is built by an event over every module that declares datasets, so the snapshot is
 * taken once per request. Two readers live in different classes — the data of the group popup in
 * the rights component and the refusal text of group deletion in its ajax action — hence a service
 * rather than the same reading written twice.
 */
final class TableCatalogService
{
	private const SERVICE_ID = 'superset';

	private const CACHE_TTL = 3600;

	/** @var array<string, string>|null */
	private static ?array $tableTitles = null;

	/** @var array<string, true>|null */
	private static ?array $userTableCodes = null;

	/**
	 * Returns the name of every table of the catalog.
	 *
	 * A row of the catalog carries the technical code first and the human-readable name next. When
	 * the row carries no name of its own — the description of the table lands there instead — the
	 * code itself becomes the name: a row of the popup always has something to show.
	 *
	 * @return array<string, string> Technical code of the table to its name.
	 */
	public static function getTableTitles(): array
	{
		if (self::$tableTitles === null)
		{
			self::$tableTitles = self::loadTableTitles();
		}

		return self::$tableTitles;
	}

	/**
	 * Tells a table made by a person from a table of the product.
	 *
	 * A dataset of an external source is named by the one who created it and lands in the catalog
	 * under that very name, so the list of those names is the whole difference between the two kinds.
	 *
	 * @param string $tableCode
	 *
	 * @return bool
	 */
	public static function isUserTable(string $tableCode): bool
	{
		if (self::$userTableCodes === null)
		{
			self::$userTableCodes = self::loadUserTableCodes();
		}

		return isset(self::$userTableCodes[$tableCode]);
	}

	/**
	 * @return void
	 */
	public static function clearCache(): void
	{
		self::$tableTitles = null;
		self::$userTableCodes = null;

		ExternalDatasetTable::cleanCache();
	}

	/**
	 * @return array<string, string>
	 */
	private static function loadTableTitles(): array
	{
		$service = Manager::getInstance()->createService(self::SERVICE_ID);
		if ($service === null)
		{
			return [];
		}

		$titles = [];
		foreach ($service->getTableList() as [$tableCode, $verboseName])
		{
			$tableCode = (string)$tableCode;
			if ($tableCode === '')
			{
				continue;
			}

			$titles[$tableCode] = is_string($verboseName) && $verboseName !== '' ? $verboseName : $tableCode;
		}

		return $titles;
	}

	/**
	 * @return array<string, true>
	 */
	private static function loadUserTableCodes(): array
	{
		$codes = [];

		$datasets = ExternalDatasetTable::getList([
			'select' => ['NAME'],
			'cache' => ['ttl' => self::CACHE_TTL],
		]);
		while ($dataset = $datasets->fetch())
		{
			$name = (string)$dataset['NAME'];
			if ($name !== '')
			{
				$codes[$name] = true;
			}
		}

		return $codes;
	}
}
