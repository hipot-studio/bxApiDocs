<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DB;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\BIConnector\DataSourceConnector\Connector\Sql;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\ExternalSource;

abstract class ExternalSql extends Sql
{
	private ?string $connectionError = null;
	private ?int $sourceId = null;
	private bool $connectionAttempted = false;

	public function __construct(
		protected string $name,
		protected FieldCollection $fields,
		public readonly array $rawInfo,
		private ?DB\Connection $connection = null,
	)
	{
	}
	protected const ANALYTIC_TAG_DATASET = 'EXTERNAL_SQL';

	abstract protected function getType(): Type;

	protected function getConnection(): ?DB\Connection
	{
		if ($this->connectionAttempted)
		{
			return $this->connection;
		}

		$this->connectionAttempted = true;
		$this->connectionError = null;
		$tableName = $this->getName();

		$dataset = ExternalDatasetTable::getList([
			'filter' => [
				'=NAME' => $tableName,
				'=TYPE' => $this->getType()->value,
			],
			'limit' => 1,
		])
			->fetchObject()
		;

		if (!$dataset)
		{
			$this->connectionError = Loc::getMessage(
				'BICONNECTOR_EXTERNAL_SQL_CONNECTOR_DATASET_NOT_FOUND',
				['#NAME#' => $tableName]
			);

			return null;
		}

		$this->sourceId = $dataset->getSourceId();

		try
		{
			/* @var ExternalSource\Source\ExternalSql $source */
			$source = Source\Factory::getSource($this->getType(), $this->sourceId);
			$connection = $source->getConnection();
		}
		catch (DB\ConnectionException $e)
		{
			$this->connectionError = Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_CONNECTION_NOT_ESTABLISHED');

			return null;
		}
		catch (\Exception $e)
		{
			$this->connectionError = $e->getMessage();

			return null;
		}

		if ($connection instanceof DB\Connection)
		{
			$this->connection = $connection;
		}
		else
		{
			$this->connectionError = Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_CONNECTION_NOT_ESTABLISHED');

			$connection = null;
		}

		return $connection;
	}

	/**
	 * @return DB\Result|bool
	 * @throws ConfigurationException
	 */
	protected function biQuery(string $sql): DB\Result|bool
	{
		$connection = $this->getConnection();
		if (!$connection)
		{
			$this->logQueryError($this->connectionError ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_NO_CONNECTION'), $sql);

			return false;
		}

		$result = $connection->biQuery($sql);
		if ($result === false)
		{
			$errorMessage = $this->getQueryErrorMessage();
			$this->logQueryError($errorMessage, $sql);
		}

		return $result;
	}

	/**
	 * @return string
	 * @throws ConfigurationException
	 */
	protected function getQueryErrorMessage(): string
	{
		$connection = $this->getConnection();
		if (!$connection)
		{
			return $this->connectionError ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_NO_CONNECTION');
		}

		if (method_exists($connection, 'getLastBiError') && $connection->getLastBiError())
		{
			return $connection->getLastBiError();
		}

		return $connection->getErrorMessage();
	}

	private function logQueryError(string $errorMessage, ?string $sql = null): void
	{
		Logger::logErrors(
			[new Error($errorMessage)],
			[
				'connectionType' => $this->getType()->value,
				'connectionId' => $this->sourceId ?? 0,
				'sql' => $sql ?? '',
			]
		);
	}
}
