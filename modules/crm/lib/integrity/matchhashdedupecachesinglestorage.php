<?php

namespace Bitrix\Crm\Integrity;

use Bitrix\Crm\Integrity\Entity\MatchHashDedupeCacheSingleStorageTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

class MatchHashDedupeCacheSingleStorage
{
	protected ?bool $isExists = null;
	protected ?string $tableName = null;
	protected ?DateTime $datasetTs = null;

	protected MatchHashDedupeQueryParams $params;

	public function __construct(MatchHashDedupeQueryParams $params)
	{
		$this->params = $params;
	}

	public static function isEnabled(): bool
	{
		return (Option::get('crm', '~enable_duplicate_table_cache', 'Y') === 'Y');
	}

	public static function dropExpired(): void
	{
		MatchHashDedupeCacheSingleStorageTable::deleteByFilter(
			[
				'<=DATASET_TS' => DateTime::createFromTimestamp(time() - static::getTableTimeToDie())
			]
		);
	}

	protected static function getTableTimeUntilOverdue(): int
	{
		return 3600;
	}

	protected static function getTableTimeToDie(): int
	{
		return (3600 * 24 * 2);
	}

	protected function getDatasetId(): string
	{
		return $this->params->getHash();
	}

	protected function getDatasetTs(): ?DateTime
	{
		return $this->datasetTs;
	}

	protected function createDataSet(Query $baseQuery): Result
	{
		$result = new Result();

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();

		$tableName = MatchHashDedupeCacheSingleStorageTable::getTableName();
		$subQuery = $baseQuery->getQuery();

		$datasetId = $sqlHelper->forSql($this->getDatasetId());
		$datetime = DateTime::createFromTimestamp(time());
		$datasetTs = $sqlHelper->convertToDbDateTime($datetime);

		$connection->startTransaction();
		if (\Bitrix\Crm\DbHelper::isPgSqlDb())
		{
			$sequenceName = "{$tableName}_{$datasetId}_sq";
			$connection->queryExecute("CREATE SEQUENCE $sequenceName AS int8");
			$query =
				/** @lang PostgreSQL */
				"INSERT INTO $tableName (DATASET_ID, DATASET_TS, RN, MATCH_HASH, QTY) " . PHP_EOL
				. "SELECT" . PHP_EOL
				. "  '$datasetId' AS DATASET_ID," . PHP_EOL
				. "  $datasetTs AS DATASET_TS," . PHP_EOL
				. "  nextval('$sequenceName') AS RN," . PHP_EOL
				. "  T1.MATCH_HASH," . PHP_EOL
				. "  T1.QTY" . PHP_EOL
				. "FROM (" . PHP_EOL
				. "  $subQuery" . PHP_EOL
				. ") T1"
			;
			$connection->queryExecute($query);
			$connection->queryExecute("DROP SEQUENCE $sequenceName;");
			unset($sequenceName);
		}
		else    // MYSQL
		{
			$varName = "@{$tableName}_{$datasetId}_rn";
			$connection->queryExecute("SET $varName = 0");
			$query =
				/** @lang MySQL */
				"INSERT INTO $tableName (DATASET_ID, DATASET_TS, RN, MATCH_HASH, QTY) " . PHP_EOL
				. "SELECT" . PHP_EOL
				. "  '$datasetId' AS DATASET_ID," . PHP_EOL
				. "  $datasetTs AS DATASET_TS," . PHP_EOL
				. "  ($varName := $varName + 1) AS RN," . PHP_EOL
				. "  T1.MATCH_HASH," . PHP_EOL
				. "  T1.QTY" . PHP_EOL
				. "FROM (" . PHP_EOL
				. "  $subQuery" . PHP_EOL
				. ") T1"
			;
			$connection->queryExecute($query);
			unset($varName);
		}
		$connection->commitTransaction();

		$this->datasetTs = $datetime;
		$this->isExists = true;

		return $result;
	}

	public function isExists(): bool
	{
		if ($this->isExists === null)
		{
			$row = MatchHashDedupeCacheSingleStorageTable::query()
				->setSelect(['DATASET_TS'])
				->where('DATASET_ID', $this->getDatasetId())
				->where(
					'DATASET_TS',
					'>',
					DateTime::createFromTimestamp(
						time() - static::getTableTimeToDie() + static::getTableTimeUntilOverdue()
					)
				)
				->setLimit(1)
				->fetch()
			;
			$isExists = (
				is_array($row)
				&& !empty($row)
				&& isset($row['DATASET_TS'])
				&& $row['DATASET_TS'] instanceof DateTime
			);
			if ($isExists)
			{
				$this->datasetTs = $row['DATASET_TS'];
			}
			$this->isExists = $isExists;
		}

		return $this->isExists;
	}

	public function create(Query $baseQuery): Result
	{
		$result = new Result();

		if (!$this->isExists())
		{
			return $this->createDataSet($baseQuery);
		}

		return $result;
	}

	public function drop(): void
	{
		MatchHashDedupeCacheSingleStorageTable::deleteByFilter(
			[
				'=DATASET_ID' => $this->getDatasetId(),
				'<=DATASET_TS' => $this->getDatasetTs() ?? new DateTime(),
			]
		);
	}

	protected function makeQuery(): Result
	{
		$result = new Result();

		$datasetTs = $this->getDatasetTs() ?? new DateTime();
		$query = MatchHashDedupeCacheSingleStorageTable::query();
		$query->setSelect(['MATCH_HASH', 'QTY'])->setOrder('RN');
		$query->setFilter(['=DATASET_ID' => $this->getDatasetId(), '=DATASET_TS' => $datasetTs]);
		$result->setData(['query' => $query]);

		return $result;
	}

	public function getQuery(): Result
	{
		if ($this->isExists())
		{
			return $this->makeQuery();
		}

		$result = new Result();
		$result->addError(new Error('Dataset is not exists.'));

		return $result;
	}
}
