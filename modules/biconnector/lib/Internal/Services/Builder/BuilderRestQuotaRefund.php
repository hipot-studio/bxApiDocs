<?php

namespace Bitrix\BIConnector\Internal\Services\Builder;

use Bitrix\BIConnector\Internal\Model\SqlLabRestRateLimitTable;
use Bitrix\BIConnector\Internal\Services\SqlLab\MasterConnectionTrait;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\Type\DateTime;

/**
 * Gives back the units the first step of BuilderRestLimiter spent when the portal step refuses the call.
 *
 * One statement takes a unit off each counter, so no lock is needed: a counter of a window that has passed
 * since is not touched, and a counter never goes below zero.
 */
class BuilderRestQuotaRefund
{
	use MasterConnectionTrait;

	/**
	 * @param string[] $dimensionKeys
	 */
	public function refund(array $dimensionKeys, int $windowStart): void
	{
		if ($dimensionKeys === [])
		{
			return;
		}

		$this->withMaster(static function (Connection $connection) use ($dimensionKeys, $windowStart): void {
			$helper = $connection->getSqlHelper();
			$table = $helper->quote(SqlLabRestRateLimitTable::getTableName());
			$keyColumn = $helper->quote('DIMENSION_KEY');
			$windowColumn = $helper->quote('WINDOW_START');
			$countColumn = $helper->quote('REQUEST_COUNT');
			$quotedKeys = array_map(
				static fn(string $key): string => "'" . $helper->forSql($key) . "'",
				$dimensionKeys,
			);
			$window = $helper->convertToDbDateTime(DateTime::createFromTimestamp($windowStart));

			$connection->queryExecute(
				"UPDATE {$table} SET {$countColumn} = {$countColumn} - 1"
				. " WHERE {$keyColumn} IN (" . implode(', ', $quotedKeys) . ')'
				. " AND {$windowColumn} = {$window} AND {$countColumn} > 0",
			);
		});
	}
}
