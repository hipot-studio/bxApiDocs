<?php
namespace Bitrix\BIConnector\Services;

use Bitrix\BIConnector\LimitManager;
use Bitrix\BIConnector\Manager;
use Bitrix\BIConnector\Service;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

class MicrosoftPowerBI extends Service
{
	protected static $serviceId = 'pbi';
	public static $dateFormats = [
		'datetime_format' => 'YYYY-MM-DD HH:MI:SS',
		'datetime_format_php' => 'Y-m-d H:i:s',
		'date_format' => 'YYYY-MM-DD',
		'date_format_php' => 'Y-m-d',
	];

	/**
	 * @inheritDoc
	 */
	public static function validateDashboardUrl(\Bitrix\Main\Event $event)
	{
		$url = $event->getParameters()[0];
		$uri = new \Bitrix\Main\Web\Uri($url);
		$isUrlOk =
			$uri->getScheme() === 'https'
			&& $uri->getHost() === 'app.powerbi.com'
			&& (
				$uri->getPath() === '/view'
				|| $uri->getPath() === '/reportEmbed'
			)
		;
		$result = new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, ($isUrlOk ? 1 : 0));
		return $result;
	}

	/**
	 * @param string $tableName
	 * @param array $parameters
	 * @param string $requestMethod
	 * @param string $requestUri
	 * @param int $limit
	 * @param LimitManager $limitManager
	 * @param array|null $queryMetadata Query metadata from Superset/Trino (bx_query_metadata).
	 * @return Result
	 */
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
		$result = new Result();
		$connector = $this->getDataSourceConnector($tableName);
		if (!$connector)
		{
			$result->addError(new Error('NO_TABLE'));

			return $result;
		}

		$manager = Manager::getInstance();
		$data = $connector->getFormattedData($parameters, static::$dateFormats);

		$databaseConnection = $manager->getDatabaseConnection();
		$databaseConnection->lock('biconnector_data', -1);

		$getIdFunction = function ($x)
		{
			return $x['ID'];
		};

		$schema = $data['schema'] ?? [];
		// A delegated aggregation describes its answer with protocol aliases, so the usage log takes the
		// field names the query touched; on the raw path the schema names them already.
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
		$convertRow = $this->getOutputRowConverter($tableName, $fields);

		$connector->sendAnalytic();

		$licenseLimit = $limitManager->getLimit();
		$isOverLimit = false;
		$count = 0;

		$queryResult = $connector->query($parameters, $limit, static::$dateFormats);
		if ($connector->isFulfilledOutput())
		{
			foreach ($queryResult as $item)
			{
				echo $item;
				$size += strlen((string)$item);
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

					if ($convertRow)
					{
						$row = $convertRow($row);
					}

					$output = ',' . Json::encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) . "\n";
					echo $output;
					$size += strlen($output);

					$count++;
				}
			}
		}

		$queryReturnResult = $queryResult->getReturn();
		$queryReturnData = $queryReturnResult instanceof Result ? $queryReturnResult->getData() : [];
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
		elseif ($queryReturnData['aggregationApplied'] ?? false)
		{
			// A delegated aggregation is printed as the application framed it, so the row count comes from
			// the gate that verified the answer. The raw path leaves no such data and keeps the count at
			// zero. The limit is soft for these queries: the excess is recorded, the answer is not cut.
			$count = (int)($queryReturnData['count'] ?? 0);
			$isOverLimit = $licenseLimit > 0 && $count > $licenseLimit;
		}

		$limitManager->fixLimit($count);
		$manager->endQuery($logId, $count, $size, $isOverLimit, $connector->getLastScanStatistics());

		$databaseConnection->unlock('biconnector_data');

		// Served row count, consumed by QueryResultCache on a cache hit for fixLimit().
		$resultData = $result->getData();
		$resultData['count'] = $count;
		$result->setData($resultData);

		return $result;
	}

	protected function printStreamedError(Result $result, Result $queryResult): int
	{
		$errorRow = $this->buildErrorRow($queryResult);
		$output = ',' . Json::encode($errorRow, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_IGNORE) . "\n]";
		echo $output;

		$this->markErrorStreamed($result);

		return strlen($output);
	}

	protected function buildErrorRow(Result $result): array
	{
		$errorsMsg = [];
		$errorsDescMsg = [];
		foreach ($result->getErrorCollection() as $error)
		{
			$errorsMsg[] = $error->getMessage();
			if (!empty($error->getCustomData()['description']))
			{
				$errorsDescMsg[] = $error->getCustomData()['description'];
			}
		}

		$errorRow = [
			'error' => implode('; ', $errorsMsg),
		];
		if (!empty($errorsDescMsg))
		{
			$errorRow['errorMessage'] = implode('; ', $errorsDescMsg);
		}

		return $errorRow;
	}

	/**
	 * Returns a converter applied to every data row right before it is encoded, or null to print rows as they are.
	 *
	 * @param string $tableName Requested table.
	 * @param string[] $fields Column IDs in output order.
	 *
	 * @return \Closure|null
	 */
	protected function getOutputRowConverter(string $tableName, array $fields): ?\Closure
	{
		return null;
	}
}
