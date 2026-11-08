<?php

namespace Bitrix\BIConnector\ExternalSource;

use Bitrix\BIConnector;

final class SupersetServiceIntegration
{
	private static ?array $tableList = null;

	/**
	 * Returns the codes of every table the service knows, as of this request.
	 *
	 * Nothing is kept between requests: a caller that reads the catalog to decide whether a name is
	 * already taken must not be answered from a list kept since yesterday, because there the error goes
	 * towards permitting - a name the caller is told is free while the table behind it already exists -
	 * and the code of a table is the identity the permission axis matches by. Building the list walks
	 * every data source of every module, so it is memoized for the hit.
	 */
	public static function getTableList(): array
	{
		if (self::$tableList !== null)
		{
			return self::$tableList;
		}

		$manager = BIConnector\Manager::getInstance();
		$service = new BIConnector\Services\ApacheSuperset($manager);

		$tableList = $service->getTableList();
		if (empty($tableList))
		{
			// An empty answer is what a service that did not answer looks like, and it is indistinguishable
			// from a portal with no tables. It is not memoized: within the same hit the next caller gets
			// a fresh attempt instead of the failure of the first one.
			return [];
		}

		self::$tableList = array_map(static fn ($table) => current($table), $tableList);

		return self::$tableList;
	}

	/**
	 * Drops the list built in this request.
	 *
	 * Nothing is kept between requests, so this is the memo of the hit and nothing else. It stays
	 * public because a caller that changes the catalog and reads it again in the same hit would
	 * otherwise be answered for a catalog that no longer exists; today only the tests do that.
	 */
	public static function clearCache(): void
	{
		self::$tableList = null;
	}
}
