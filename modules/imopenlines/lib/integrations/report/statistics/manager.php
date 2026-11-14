<?php
namespace Bitrix\ImOpenLines\Integrations\Report\Statistics;


use Bitrix\ImOpenLines\Integrations\Report\Statistics\Entity\StatisticQueueTable;
use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\DB\Connection;
use Bitrix\Main\DB\TransactionException;
use Bitrix\Main\Type\DateTime;

/**
 * Class Manager
 * @package Bitrix\ImOpenLines\Integrations\Report\Statistics
 */
abstract class Manager
{

	const TREATMENT_STATISTIC_KEY = 'treatment';
	/**
	 * for optimisation
	 */
	const TREATMENT_BY_HOUR_STATISTIC_KEY = 'treatment_by_hour';
	const MARK_STATISTIC_KEY = 'mark';
	const DIALOG_CREATE_STATISTIC_KEY = 'dialog_create';
	const DIALOG_SKIP_STATISTIC_KEY = 'dialog_skip';
	const DIALOG_ANSWER_STATISTIC_KEY = 'dialog_answer';

	private const TRANSACTION_BATCH_SIZE = 500;
	private const NESTED_ROLLBACK_MESSAGE = 'Nested rollbacks are unsupported.';
	/**
	 * @return array
	 */
	private static function getAggregateManagersMap()
	{
		return array(
			self::TREATMENT_STATISTIC_KEY => Treatment::getClassName(),
			self::TREATMENT_BY_HOUR_STATISTIC_KEY => TreatmentByHour::getClassName(),
			self::MARK_STATISTIC_KEY => Mark::getClassName(),
			self::DIALOG_SKIP_STATISTIC_KEY => Dialog::getClassName(),
			self::DIALOG_ANSWER_STATISTIC_KEY => Dialog::getClassName(),
			self::DIALOG_CREATE_STATISTIC_KEY => Dialog::getClassName(),
		);
	}



	public static function writeToStatistics($statisticKey, array $params)
	{
		$map = self::getAggregateManagersMap();
		if (!empty($map[$statisticKey]))
		{
			/** @var AggregateStrategy $aggregator */
			$aggregator = new $map[$statisticKey]($params);
			if ($aggregator instanceof AggregateStrategy)
			{
				if (!$aggregator->getErrors())
				{
					if ($existingRecord = $aggregator->getExistingRecordByPrimary())
					{
						$aggregator->updateRecord($existingRecord);
					}
					else
					{
						$aggregator->createRecord();
					}
				}
			}
		}
	}


	public static function getFromQueue($primary)
	{
		return  StatisticQueueTable::getByPrimary($primary);
	}

	/**
	 * @param $sessionId
	 * @param $statisticKey
	 * @param array $additionalParams
	 */
	public static function addToQueue($sessionId, $statisticKey, $additionalParams = array())
	{
		$isPreCalculationFinish = Option::get("imopenlines", 'statisticPreCalculateFinish', false);
		if ($isPreCalculationFinish)
		{
			StatisticQueueTable::add(array(
				'fields' => array(
					'SESSION_ID' => $sessionId,
					'STATISTIC_KEY' => $statisticKey,
					'DATE_QUEUE' => new DateTime(),
					'PARAMS' => QueuePayload::encode($additionalParams),
				)
			));
		}
	}

	public static function removeFromQueue($primary)
	{
		StatisticQueueTable::delete($primary);
	}

	/**
	 * @return string
	 */
	public static function calculateStatisticsInQueue(int $pageSize = 100000): string
	{
		$connection = Application::getConnection();
		$dateLimit = new DateTime();
		$lastId = 0;
		do
		{
			// Processed rows are deleted, so the page is taken by key rather than by offset
			$cursor = StatisticQueueTable::query()
				->setSelect(['ID', 'STATISTIC_KEY', 'PARAMS'])
				->where('ID', '>', $lastId)
				->where('DATE_QUEUE', '<=', $dateLimit)
				->setOrder(['ID' => 'ASC'])
				->setLimit($pageSize)
				->exec()
			;
			$hasResultData = false;
			// One commit per batch; each row gets its own savepoint inside processQueueItem()
			$connection->startTransaction();
			try
			{
				$batchCount = 0;
				while ($resultFromQueue = $cursor->fetch())
				{
					$hasResultData = true;
					$lastId = (int)$resultFromQueue['ID'];
					self::processQueueItem($resultFromQueue);

					if (++$batchCount >= self::TRANSACTION_BATCH_SIZE)
					{
						$connection->commitTransaction();
						$connection->startTransaction();
						$batchCount = 0;
					}
				}
				$connection->commitTransaction();
			}
			catch (\Throwable $exception)
			{
				// The whole batch is rolled back and stays in the queue for the next run
				self::rollbackTransaction($connection);
				self::writeToLog($exception);

				break;
			}
		}
		while ($hasResultData);

		return __METHOD__. '();';
	}

	private static function processQueueItem(array $row): void
	{
		try
		{
			$params = is_array($row['PARAMS']) ? QueuePayload::decode($row['PARAMS']) : null;
		}
		catch (ArgumentException $exception)
		{
			self::writeToLog($exception);
			self::removeFromQueue($row['ID']);

			return;
		}

		// A failed write keeps the row for the next run and leaves no partial aggregates
		$connection = Application::getConnection();
		$connection->startTransaction();
		try
		{
			if ($params !== null)
			{
				self::writeQueueItemToStatistics($row['STATISTIC_KEY'], $params);
			}
			self::removeFromQueue($row['ID']);
			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			self::rollbackTransaction($connection);
			self::writeToLog($exception);
		}
	}

	private static function rollbackTransaction(Connection $connection): void
	{
		try
		{
			$connection->rollbackTransaction();
		}
		catch (TransactionException $exception)
		{
			// Inside an outer transaction the rollback to the savepoint is already done
			if ($exception->getMessage() !== self::NESTED_ROLLBACK_MESSAGE)
			{
				throw $exception;
			}
		}
	}

	private static function writeQueueItemToStatistics($statisticNameKey, array $params): void
	{
		if (is_array($params['OPERATOR_ID'] ?? null))
		{
			$savedParams = $params;
			foreach ($params['OPERATOR_ID'] as $operatorId)
			{
				$savedParams['OPERATOR_ID'] = $operatorId;
				self::writeToStatistics($statisticNameKey, $savedParams);
			}
		}
		else
		{
			self::writeToStatistics($statisticNameKey, $params);
		}
	}

	private static function writeToLog(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}