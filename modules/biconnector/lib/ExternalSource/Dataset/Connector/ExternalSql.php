<?php

namespace Bitrix\BIConnector\ExternalSource\Dataset\Connector;

use Bitrix\BIConnector\DataSourceConnector\Aggregation\ScopeId;
use Bitrix\BIConnector\DataSourceConnector\FieldCollection;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalDatasetTable;
use Bitrix\BIConnector\ExternalSource\Type;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\Main\Config\ConfigurationException;
use Bitrix\Main\DB;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\BIConnector\DataSourceConnector\Connector\Sql;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\ExternalSource;

abstract class ExternalSql extends Sql
{
	private ?string $connectionError = null;
	private ?int $sourceId = null;
	private bool $connectionAttempted = false;
	private ?string $querySql = null;
	private bool $queryErrorLogged = false;

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

	public function query(array $parameters, int $limit, array $dateFormats = []): \Generator
	{
		if (!in_array($this->getType(), [Type::Mysql, Type::Pgsql], true))
		{
			return yield from parent::query($parameters, $limit, $dateFormats);
		}

		$this->querySql = null;
		$this->queryErrorLogged = false;
		if (($this->rawInfo['TABLE_NAME'] ?? '') === '')
		{
			return (new Result())->addError($this->getPublicQueryError());
		}

		try
		{
			$result = yield from parent::query($parameters, $limit, $dateFormats);
		}
		catch (DB\SqlQueryException $exception)
		{
			$this->restoreSqlTimeLimit();
			$this->logQueryError($exception->getDatabaseMessage() ?: $exception->getMessage(), $this->querySql);

			return (new Result())->addError($this->getPublicQueryError());
		}

		if ($result->isSuccess())
		{
			return $result;
		}

		$publicResult = (new Result())->setData($result->getData());
		foreach ($result->getErrors() as $error)
		{
			if ($error->getMessage() !== 'SQL_ERROR')
			{
				$publicResult->addError($error);

				continue;
			}

			if (!$this->queryErrorLogged)
			{
				$this->logQueryError($error->getCustomData()['description'] ?? $error->getMessage(), $this->querySql);
			}
			$publicResult->addError($this->getPublicQueryError());
		}

		return $publicResult;
	}

	private function getPublicQueryError(): Error
	{
		return new Error(
			'SQL_ERROR',
			0,
			['description' => Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_QUERY_ERROR') ?? ''],
		);
	}

	/**
	 * External datasets are identified by their source, so the same table name backed by a different
	 * connection yields a different scope and invalidates the capabilities cached by the plugin.
	 */
	protected function getAggregationScopeId(): string
	{
		$this->getConnection();

		return $this->sourceId === null
			? ''
			: ScopeId::forExternalSource($this->sourceId);
	}

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
				['#NAME#' => $tableName],
			);

			return null;
		}

		$this->sourceId = $dataset->getSourceId();

		try
		{
			$connection = $this->createExternalConnection($this->sourceId ?? 0);
		}
		catch (DB\ConnectionException)
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

	protected function createExternalConnection(int $sourceId): ?DB\Connection
	{
		/* @var ExternalSource\Source\ExternalSql $source */
		$source = Source\Factory::getSource($this->getType(), $sourceId);

		return $source->getConnection(null, $this->sqlTimeLimitSeconds);
	}

	/**
	 * @return DB\Result|bool
	 * @throws ConfigurationException
	 */
	protected function biQuery(string $sql): DB\Result|bool
	{
		$this->querySql = $sql;
		$connection = $this->getConnection();
		if (!$connection)
		{
			$this->logQueryError($this->connectionError ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_NO_CONNECTION') ?? '', $sql);

			return false;
		}

		$result = $this->executeBiQuery($connection, $sql);
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
		if ($this->biQueryError !== null)
		{
			return $this->biQueryError;
		}

		$connection = $this->getConnection();
		if (!$connection)
		{
			return $this->connectionError ?? Loc::getMessage('BICONNECTOR_EXTERNAL_SQL_CONNECTOR_NO_CONNECTION') ?? '';
		}

		if (method_exists($connection, 'getLastBiError') && $connection->getLastBiError())
		{
			return $connection->getLastBiError();
		}

		return $connection->getErrorMessage();
	}

	private function logQueryError(string $errorMessage, ?string $sql = null): void
	{
		$this->queryErrorLogged = true;
		Logger::logErrors(
			[new Error($errorMessage)],
			[
				'connectionType' => $this->getType()->value,
				'connectionId' => $this->sourceId ?? 0,
				'sql' => $sql ?? '',
			],
		);
	}
}
