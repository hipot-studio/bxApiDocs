<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\IpAddress;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceSettingsCollection;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\SourceManager;
use Bitrix\BIConnector\ExternalSource\Source\DB\ExternalSqlConnectionInterface;

abstract class ExternalSql extends Base
{
	private const NO_CONNECTION_FLAGS = 0;

	protected int $id;
	private ?string $lastConnectionError = null;

	abstract protected function buildConnection(array $config): ExternalSqlConnectionInterface;

	/**
	 * @param int $id source id
	 */
	public function __construct(?int $sourceId)
	{
		parent::__construct($sourceId);

		if ($sourceId > 0)
		{
			$this->source = ExternalSourceTable::getList([
				'filter' => ['=ID' => $sourceId],
			])
				->fetchObject()
			;
		}
	}

	/**
	 * @return int
	 */
	public function getConnection(?ExternalSourceSettingsCollection $settings = null): ?ExternalSqlConnectionInterface
	{
		$this->lastConnectionError = null;

		if (empty($settings) && !empty($this->source))
		{
			$settings = SourceManager::getSourceSettings($this->source);
		}

		if (empty($settings))
		{
			$this->lastConnectionError = Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_LAST_CONNECTION_ERROR_EMPTY_SETTINGS');

			return null;
		}

		$config = [];
		$connectionFields = ['host', 'login', 'database', 'password'];
		foreach ($connectionFields as $field)
		{
			$value = $settings->getValueByCode($field);
			if ($value === null)
			{
				$this->lastConnectionError = Loc::getMessage(
					'BICONNECTOR_EXTERNAL_SQL_LAST_CONNECTION_ERROR_MISSING_FIELD',
					['#FIELD#' => $field]
				);

				return null;
			}

			$config[$field] = $value;
		}

		if (Main\Loader::includeModule('bitrix24'))
		{
			$ip = IpAddress::createByName($config['host']);
			if ($ip->isPrivate())
			{
				$this->lastConnectionError = Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_PRIVATE_IP');

				return null;
			}
		}


		$port = (int)$settings->getValueByCode('port');
		if ($port > 0)
		{
			$config['host'] = "{$config['host']}:{$port}";
		}

		$config['options'] = self::NO_CONNECTION_FLAGS;

		try
		{
			$connection = $this->buildConnection($config);
			$connection->connect();
		}
		catch (Main\DB\ConnectionException $e)
		{
			$this->lastConnectionError = $e->getMessage();

			return null;
		}

		return $connection;
	}

	public function getLastConnectionError(): ?string
	{
		return $this->lastConnectionError;
	}

	/**
	 * Connects to external source
	 */
	public function connect(ExternalSourceSettingsCollection $settings): Main\Result
	{
		$result = new Main\Result();

		$connection = $this->getConnection($settings);
		if (!$connection)
		{
			$error = $this->getLastConnectionError() ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_NOT_CONNECTED');
			$result->addError(new Main\Error($error));

			return $result;
		}

		try
		{
			$connection->connect();
			$connection->getVersion();
		}
		catch (\Bitrix\Main\DB\ConnectionException $e)
		{
			$errorMessage = $e->getMessage();
			if (empty($errorMessage))
			{
				$result->addError(new Main\Error(Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_NOT_CONNECTED')));
			}
			else
			{
				$result->addError(new Main\Error(Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR', ['#ERROR_MESSAGE#' => $errorMessage])));
			}
		}

		return $result;
	}

	/**
	 * @return array
	 */
	public function getEntityList(?string $searchString = null): Main\Result
	{
		$result = new Main\Result();
		$connection = $this->getConnection();
		if (!$connection)
		{
			$error = $this->getLastConnectionError() ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_NOT_CONNECTED');
			$result->addError(new Main\Error($error));

			return $result;
		}

		$formatted = [];
		foreach ($connection->showTables() as $tableName)
		{
			if (empty($searchString) || mb_stripos($tableName, $searchString) !== false)
			{
				$formatted[] = [
					'ID' => $tableName,
					'TITLE' => $tableName,
					'DESCRIPTION' => '',
					'DATASET_NAME' => $tableName,
				];
			}
		}

		$result->setData($formatted);

		return $result;
	}

	/**
	 * @param string $entityName
	 * @return array
	 */
	public function getDescription(string $entityName): array
	{
		$connection = $this->getConnection();
		if (!$connection)
		{
			$error = $this->getLastConnectionError() ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_NOT_CONNECTED');

			throw new Main\SystemException($error);
		}

		if (!$connection->isTableExists($entityName))
		{
			throw new Main\ArgumentException(
				Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_TABLE_NOT_FOUND', ['#TABLE_NAME#' => $entityName]),
				'entityName',
			);
		}

		return $connection->getTableFields($entityName);
	}

	/**
	 * @param string $entityName
	 * @param int $n
	 * @param array $fields
	 * @return array
	 */
	public function getFirstNData(string $entityName, int $n, array $fields = []): array
	{
		$result = [];

		$connection = $this->getConnection();
		if (!$connection)
		{
			$error = $this->getLastConnectionError() ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTION_ERROR_NOT_CONNECTED');

			throw new Main\SystemException($error);
		}

		if ($n < 0)
		{
			throw new Main\ArgumentException(Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_GET_FIRST_N_DATA_INVALID_N'), 'n');
		}

		if (!$connection->isTableExists($entityName))
		{
			throw new Main\ArgumentException(
				Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_TABLE_NOT_FOUND', ['#TABLE_NAME#' => $entityName]),
				'entityName',
			);
		}

		$entityName = $connection->getSqlHelper()->quote($entityName);
		$query = sprintf('SELECT * FROM %s LIMIT %d', $entityName, $n);
		try
		{
			$flippedFields = array_flip($fields);
			$queryResult = $connection->query($query);
			while ($row = $queryResult->fetch())
			{
				if (!empty($fields))
				{
					$row = array_intersect_key($row, $flippedFields);
				}

				$result[] = $row;
			}
		}
		catch (Main\DB\SqlQueryException $exception)
		{
			$result = [];
		}

		return $result;
	}
}
