<?php
namespace Bitrix\BIConnector\Services;

use Bitrix\BIConnector\DataSourceConnector\ApacheSupersetFieldDto;
use Bitrix\BIConnector\DataSourceConnector\Connector;
use Bitrix\BIConnector\DataSourceConnector\FieldDto;
use Bitrix\BIConnector\LimitManager;
use Bitrix\BIConnector\Manager;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class ApacheSuperset extends MicrosoftPowerBI
{
	public const TYPE_STRING = 'STRING';
	public const TYPE_INT = 'INT';
	public const TYPE_DOUBLE = 'DOUBLE';
	public const TYPE_BOOLEAN = 'BOOLEAN';
	public const TYPE_DATE = 'DATE';
	public const TYPE_DATETIME = 'DATETIME';
	public const TYPE_ARRAY_STRING = 'ARRAY_STRING';
	public const TYPE_MAP_STRING = 'MAP_STRING';


	protected static $serviceId = 'superset';

	private ?int $sqlTimeLimitSeconds = null;

	private bool $isLoadProtectionEnabled = false;

	public function setSqlTimeLimitSeconds(?int $seconds): void
	{
		$this->sqlTimeLimitSeconds = ($seconds !== null && $seconds > 0) ? $seconds : null;
	}

	public function setLoadProtectionEnabled(bool $isEnabled): void
	{
		$this->isLoadProtectionEnabled = $isEnabled;
	}

	/**
	 * Adds the full table description as the third element of a table-list row.
	 *
	 * Only this service reads it: the description travels to Superset through the table comment the
	 * Trino connector builds out of this row. The external services (Google Data Studio, Power BI)
	 * read the row by index and expect the two elements they have always got, so the description is
	 * published here rather than in the shared row.
	 *
	 * @param Connector\Base $connector
	 *
	 * @return array
	 */
	protected function buildTableListRow(Connector\Base $connector): array
	{
		$row = parent::buildTableListRow($connector);
		$row[] = $connector->rawInfo['TABLE_DESCRIPTION_FULL'] ?? '';

		return $row;
	}

	/**
	 * @param string $fieldName
	 * @param array $fieldInfo
	 *
	 * @return FieldDto
	 */
	protected function prepareFieldDto(string $fieldName, array $fieldInfo): FieldDto
	{
		$type = $fieldInfo['FIELD_TYPE_EX'] ?? $fieldInfo['FIELD_TYPE'];

		$parentDto = parent::prepareFieldDto($fieldName, $fieldInfo);

		return new ApacheSupersetFieldDto(
			$parentDto->id,
			$parentDto->name,
			$parentDto->description,
			$type ?? 'string',
			$parentDto->isMetric,
			$parentDto->isPrimary,
			$parentDto->isSystem,
			$parentDto->aggregationType,
			$parentDto->groupKey,
			$parentDto->groupConcat,
			$parentDto->groupCount,
			$parentDto->isValueSplitable
		);
	}

	/**
	 * @param string $name
	 *
	 * @return Connector\Base|null
	 */
	public function getDataSourceConnector(string $name): ?Connector\Base
	{
		// Both scopes have to have missed before the absence is remembered, so the flag is consulted here
		// and set only at the end: the parent sets it after the legacy scope alone, which says nothing
		// about the BI Builder scope below.
		if ($this->isMissingDataSource($name))
		{
			return null;
		}

		$connector = parent::getDataSourceConnector($name);

		if (!empty($connector))
		{
			return $this->applySqlTimeLimit($connector);
		}

		$this->forgetMissingDataSource($name);

		$dataSources = [];
		$event = new \Bitrix\Main\Event('biconnector', 'OnBIBuilderDataSources', [
			$this->manager,
			&$dataSources,
			$this->languageId,
			$name,
		]);
		$event->send();

		if (empty($dataSources[$name]) || !($dataSources[$name] instanceof Connector\Base))
		{
			$this->rememberMissingDataSource($name);

			return null;
		}

		// Same single-snapshot rule as the parent: the description is asked for several times within one
		// request, and the headers that confirm the answer must describe the body that was built.
		return $this->applySqlTimeLimit($this->rememberDataSourceConnector($dataSources[$name]));
	}

	private function applySqlTimeLimit(Connector\Base $connector): Connector\Base
	{
		if ($this->isLoadProtectionEnabled)
		{
			$connector->setSqlTimeLimitSeconds($this->sqlTimeLimitSeconds);
		}

		return $connector;
	}

	/**
	 * Returns all available data sources descriptions.
	 *
	 * @return array
	 */
	protected function loadDataSourceConnectors(): array
	{
		$dataSourceConnectors = parent::loadDataSourceConnectors();

		$dataSources = [];
		$event = new \Bitrix\Main\Event('biconnector', 'OnBIBuilderDataSources', [
			$this->manager,
			&$dataSources,
			$this->languageId,
			null,
		]);
		$event->send();

		foreach ($dataSources as $source)
		{
			if ($source instanceof Connector\Base && !isset($dataSourceConnectors[$source->getName()]))
			{
				$dataSourceConnectors[$source->getName()] = $source;
			}
		}

		return $dataSourceConnectors;
	}

	/**
	 *
	 * @deprecated
	 *
	 * Type mapping is realised into Bitrix\BIConnector\DataSourceConnector\ApacheSupersetFieldDto
	 *
	 * Returns trino supported type by internal.
	 *
	 * @param string $internalType Internal (CSQLWhere) type.
	 * @param null $fieldName Field name - ID, TITLE, UF_CRM_100 etc.
	 *
	 * @see \CSQLWhere
	 */
	protected function mapType($internalType, $fieldName = null): string
	{
		if (is_string($fieldName) && str_starts_with($fieldName, 'UF_'))
		{
			return self::TYPE_STRING;
		}

		return match ($internalType)
		{
			'file', 'enum', 'int' => self::TYPE_INT,
			'double' => self::TYPE_DOUBLE,
			'date' => self::TYPE_DATE,
			'datetime' => self::TYPE_DATETIME,
			'bool' => self::TYPE_BOOLEAN,
			'array_string' => self::TYPE_ARRAY_STRING,
			'map_string' => self::TYPE_MAP_STRING,
			default => self::TYPE_STRING,
		};
	}

	private const ABORT_CHECK_ROW_INTERVAL = 500;

	public function printQuery(
		string $tableName,
		array $parameters,
		string $requestMethod,
		string $requestUri,
		int $limit,
		LimitManager $limitManager,
		?array $queryMetadata = null,
	): Result
	{
		if (!$this->isLoadProtectionEnabled)
		{
			return parent::printQuery(
				$tableName,
				$parameters,
				$requestMethod,
				$requestUri,
				$limit,
				$limitManager,
				$queryMetadata,
			);
		}

		$result = new Result();
		$connector = $this->getDataSourceConnector($tableName);
		if (!$connector)
		{
			$result->addError(new Error('NO_TABLE'));

			return $result;
		}

		$manager = Manager::getInstance();
		$data = $connector->getFormattedData($parameters, static::$dateFormats);

		$getIdFunction = function ($x)
		{
			return $x['ID'];
		};

		$schema = $data['schema'] ?? [];
		$statFields = $data['usageStatFields'] ?? [];
		if (!$statFields)
		{
			$statFields = array_map($getIdFunction, $schema);
		}

		$logId = $manager->startQuery(
			$tableName,
			implode(', ', $statFields),
			Json::encode($data['where'], JSON_UNESCAPED_UNICODE),
			Json::encode($parameters, JSON_UNESCAPED_UNICODE),
			$requestMethod,
			preg_replace('/(?:^|\\?|&)token=(.+?)(?:$|&)/', 'token=hide-the-key-from-the-log', $requestUri),
			$queryMetadata
		);

		$fields = [];
		foreach ($schema as $fieldInfo)
		{
			$fields[] = $fieldInfo['ID'];
		}

		$size = 0;
		$countColumns = count($fields);
		$firstLinePrinted = false;

		$connector->sendAnalytic();

		$licenseLimit = $limitManager->getLimit();
		$isOverLimit = false;
		$count = 0;

		$aborted = false;
		$rowsSinceCheck = 0;

		$connector->setAbortCheck(static function () use (&$aborted): bool {
			flush();
			if (connection_aborted())
			{
				$aborted = true;

				return true;
			}

			return false;
		});

		$queryResult = $connector->query($parameters, $limit, static::$dateFormats);
		if ($connector->isFulfilledOutput())
		{
			foreach ($queryResult as $item)
			{
				echo $item;
				$size += strlen((string)$item);

				if (++$rowsSinceCheck >= self::ABORT_CHECK_ROW_INTERVAL)
				{
					$rowsSinceCheck = 0;
					flush();
					if (connection_aborted())
					{
						$aborted = true;
						$connector->abortActiveQuery();

						break;
					}
				}
			}
		}
		else
		{
			foreach ($queryResult as $row)
			{
				if (!$firstLinePrinted)
				{
					$output = "[\n" . Json::encode($fields, JSON_UNESCAPED_UNICODE) . "\n";
					echo $output;
					$size += strlen($output);
					$firstLinePrinted = true;
				}
				elseif ($licenseLimit > 0 && $licenseLimit <= $count)
				{
					$count++;

					if (++$rowsSinceCheck >= self::ABORT_CHECK_ROW_INTERVAL)
					{
						$rowsSinceCheck = 0;
						flush();
						if (connection_aborted())
						{
							$aborted = true;
							$connector->abortActiveQuery();

							break;
						}
					}

					continue;
				}

				if (is_array($row))
				{
					$rowCount = count($row);
					if ($countColumns < $rowCount)
					{
						$row = array_slice($row, 0, $countColumns);
					}
					elseif ($countColumns > $rowCount)
					{
						$row += array_fill($rowCount, $countColumns - $rowCount, null);
					}

					$output = ',' . Json::encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) . "\n";
					echo $output;
					$size += strlen($output);

					$count++;

					if (++$rowsSinceCheck >= self::ABORT_CHECK_ROW_INTERVAL)
					{
						$rowsSinceCheck = 0;
						flush();
						if (connection_aborted())
						{
							$aborted = true;
							$connector->abortActiveQuery();

							break;
						}
					}
				}
			}
		}

		if (!$aborted)
		{
			$queryReturnResult = $queryResult->getReturn();
			if ($queryReturnResult instanceof Result && !$queryReturnResult->isSuccess())
			{
				$result->addErrors($queryReturnResult->getErrors());

				if ($firstLinePrinted)
				{
					$size += $this->printStreamedError($result, $queryReturnResult);
				}
			}
			elseif (!$connector->isFulfilledOutput())
			{
				if (!$firstLinePrinted)
				{
					$output = "[\n" . Json::encode($fields, JSON_UNESCAPED_UNICODE) . "\n";
					echo $output;
					$size += strlen($output);
				}

				if ($licenseLimit > 0 && $licenseLimit < $count)
				{
					$overLimit = $count - $licenseLimit;
					$errorRow = [
						'error' => 'OVER_LIMIT',
						'errorMessage' => Loc::getMessagePlural(
							'BICONNECTOR_TABLE_OVER_LIMIT_ERROR_MSGVER_1',
							$overLimit,
							[
								'#LIMIT#' => $this->formatLimitValue($licenseLimit),
								'#OVERLIMIT_COUNT#' => $this->formatLimitValue($overLimit),
							],
							$this->getLanguage(),
						),
					];

					$output = ',' . Json::encode($errorRow, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) . "\n";
					echo $output;

					$isOverLimit = true;
				}

				$output = ']';
				echo $output;
				$size += strlen($output);
			}
		}

		$limitManager->fixLimit($count);
		$manager->endQuery($logId, $count, $size, $isOverLimit, $connector->getLastScanStatistics());

		$resultData = $result->getData();
		$resultData['count'] = $count;
		$result->setData($resultData);

		return $result;
	}
}
