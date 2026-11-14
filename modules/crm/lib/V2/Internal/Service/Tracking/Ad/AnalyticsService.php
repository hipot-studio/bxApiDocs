<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Tracking\Ad;

use Bitrix\Crm\Tracking\Analytics\DataProvider;
use Bitrix\Crm\Tracking\Analytics\Provider\Action;
use Bitrix\Crm\Tracking\Analytics\Provider\Base;
use Bitrix\Crm\Tracking\Provider;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Period;
use Bitrix\Crm\V2\Internal\Entity\Tracking\Ad\Refusal;
use Bitrix\Main\Type\DateTime;

final class AnalyticsService
{
	public function list(array $sourceIds, Period $period): array
	{
		(new Report())->assertAvailable();
		$actualSources = [];
		foreach (Provider::getActualSources() as $source)
		{
			$sourceId = (int)$source['ID'];
			if ($sourceId > 0 && in_array($sourceId, $sourceIds, true))
			{
				$actualSources[$sourceId] = true;
			}
		}
		if (count($actualSources) !== count(array_unique($sourceIds)))
		{
			throw Refusal::SourceNotFound->exception();
		}

		$provider = (new DataProvider())
			->setSourceId(array_keys($actualSources))
			->groupByTrackingSource(true)
			->groupByAssigned(false)
			->setPeriod(
				new DateTime($period->dateFrom . ' 00:00:00', 'Y-m-d H:i:s'),
				new DateTime($period->dateTo . ' 23:59:59', 'Y-m-d H:i:s'),
			);
		$stagesBySource = [];
		foreach ($actualSources as $sourceId => $_)
		{
			$stagesBySource[$sourceId] = [];
		}
		foreach ($provider->getProviders() as $stageProvider)
		{
			if ($stageProvider instanceof Action)
			{
				$stageProvider->restrictExpensesToSources(array_keys($actualSources));
			}
			$code = (string)$stageProvider->getCode();
			foreach ($actualSources as $sourceId => $_)
			{
				$stagesBySource[$sourceId][$code] ??= [
					'code' => $code,
					'quantity' => 0,
					'sum' => 0.0,
					'views' => 0,
				];
			}
			foreach ($stageProvider->getData() as $row)
			{
				$sourceId = (int)($row[Base::TrackingSourceId] ?? 0);
				if (!isset($actualSources[$sourceId]))
				{
					continue;
				}
				$stage = &$stagesBySource[$sourceId][$code];
				$stage['quantity'] += (int)($row['CNT'] ?? 0);
				$stage['sum'] += (float)($row['SUM'] ?? 0);
				$stage['views'] += (int)($row['VIEWS'] ?? 0);
				unset($stage);
			}
		}

		ksort($stagesBySource, SORT_NUMERIC);
		$result = [];
		foreach ($stagesBySource as $sourceId => $stages)
		{
			$cost = $stages[Action::CODE]['sum'] ?? 0.0;
			$lastStage = $stages === [] ? null : end($stages);
			$income = $lastStage['sum'] ?? 0.0;
			$result[] = [
				'sourceId' => $sourceId,
				'dateFrom' => $period->dateFrom,
				'dateTo' => $period->dateTo,
				'currencyId' => \CCrmCurrency::GetAccountCurrencyID(),
				'stages' => array_values($stages),
				'cost' => $cost,
				'income' => $income,
				'roi' => $cost ? round(($income - $cost) / $cost * 100, 2) : 0.0,
			];
		}

		return $result;
	}
}
