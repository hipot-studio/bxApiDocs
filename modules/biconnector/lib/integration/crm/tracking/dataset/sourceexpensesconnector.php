<?php

namespace Bitrix\BIConnector\Integration\Crm\Tracking\Dataset;

use Bitrix\BIConnector\DataSourceConnector\Connector\Base;
use Bitrix\BIConnector\Integration\Crm\Tracking\ExpensesAggregator;
use Bitrix\BIConnector\Integration\Crm\Tracking\ExpensesProvider\ProviderFactory;
use Bitrix\Crm\Tracking\Internals\SourceExpensesTable;
use Bitrix\Crm\Tracking;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\Date;

class SourceExpensesConnector extends Base
{
	private const PREVIEW_DAYS_WINDOW = 30;

	/**
	 * @return bool
	 */
	protected function isNeedApplyTimezoneOffset(): bool
	{
		return false;
	}

	/**
	 * Expenses are fetched from an external API, so the preview loads the last 30 days
	 * instead of the first N rows.
	 *
	 * @return array
	 */
	public function getPreviewParameters(): array
	{
		$endDate = new Date();
		$startDate = (clone $endDate)->add('-' . self::PREVIEW_DAYS_WINDOW . ' days');

		return [
			'dateRange' => [
				'startDate' => $startDate->format('Y-m-d'),
				'endDate' => $endDate->format('Y-m-d'),
			],
		];
	}

	public function query(
		array $parameters,
		int $limit,
		array $dateFormats = []
	): \Generator
	{
		$result = new Result();

		$dataResult = $this->getData($parameters, $dateFormats);
		if (!$dataResult->isSuccess())
		{
			foreach ($dataResult->getErrorMessages() as $errorMessage)
			{
				$result->addError(new Error('QUERY_ERROR', 0, ['description' => $errorMessage]));
			}

			return $result;
		}

		$dto = $dataResult->getConnectorData();
		if ($dto === null || empty($dto->getColumns()))
		{
			$result->addError(new Error('QUERY_ERROR', 0, ['description' => 'No column selected']));

			return $result;
		}

		$endDate = (new Date())->add('+1 day');
		if (!empty($dto->getFilterValue('<=DATE')))
		{
			$endDate = strtotime($dto->getFilterValue('<=DATE'));
			$endDate = Date::createFromTimestamp($endDate);
		}

		$startDateTimestamp = null;
		if (!empty($dto->getFilterValue('>=DATE')))
		{
			$startDateTimestamp = strtotime($dto->getFilterValue('>=DATE'));
		}

		$startDate = clone($endDate);
		$startDate->add('-30 days');
		if ($startDateTimestamp)
		{
			$startDate = Date::createFromTimestamp($startDateTimestamp);
		}

		$aggregator = new ExpensesAggregator(
			...ProviderFactory::getAvailableProviders()
		);
		$dailyExpensesResult = $aggregator->buildDailyExpensesReport($startDate, $endDate);

		if (!$dailyExpensesResult->isSuccess())
		{
			return $result->addErrors($dailyExpensesResult->getErrors());
		}

		$summaryExpenses = $dailyExpensesResult->getData();
		foreach ($summaryExpenses as &$expense)
		{
			$expense['TIMESTAMP'] = 0;
			if ($expense['DATE'])
			{
				$expense['TIMESTAMP'] = (new Date($expense['DATE'], 'Y-m-d H:i:s'))->getTimestamp();
			}
		}
		unset($expense);

		foreach ($this->getCustomUserExpenses($startDate, $endDate) as $expense)
		{
			$summaryExpenses[] = $expense;
		}

		usort($summaryExpenses, static fn($a, $b) => $a['TIMESTAMP'] >= $b['TIMESTAMP']);

		if ($limit > 0 && count($summaryExpenses) > $limit)
		{
			$summaryExpenses = array_slice($summaryExpenses, 0, $limit);
		}

		foreach ($summaryExpenses as $expense)
		{
			$item = [];
			foreach ($dto->getColumns() as $code)
			{
				if (isset($expense[$code]))
				{
					$item[$code] = $expense[$code];
				}
				else
				{
					$item[$code] = '';
				}
			}

			yield array_values($item);
		}

		return $result;
	}

	/**
	 * @param Date $startDate
	 * @param Date $endDate
	 *
	 * @return \Generator
	 */
	private function getCustomUserExpenses(Date $startDate, Date $endDate): \Generator
	{
		$sources = Tracking\Provider::getActualSources();
		$sourceIds = array_column($sources, 'ID');
		$rows = SourceExpensesTable::getList([
			'select' => [
				'CURRENCY' => 'CURRENCY_ID',
				'EXPENSES',
				'DATE' => 'DATE_STAT',
				'ACTIONS',
				'CLICKS' => 'ACTIONS',
				'IMPRESSIONS',
				'SOURCE_ID',
			],
			'filter' => [
				'=SOURCE_ID' => $sourceIds,
				'>=DATE_STAT' => $startDate,
				'<=DATE_STAT' => $endDate,
				'=TYPE_ID' => SourceExpensesTable::TYPE_MANUAL,
			],
		]);

		while ($row = $rows->fetch())
		{
			$row['CAMPAIGN_ID'] = '';
			$row['CAMPAIGN_NAME'] = '';
			$row['TIMESTAMP'] = $row['DATE']->getTimestamp();
			$row['CPC'] =
				$row['ACTIONS'] > 0
					? round($row['EXPENSES'] / $row['ACTIONS'], 2)
					: 0
			;

			$row['CPM'] =
				$row['IMPRESSIONS'] > 0
					? round($row['EXPENSES'] / ($row['IMPRESSIONS'] * 1000), 2)
					: 0
			;

			yield $row;
		}
	}
}
