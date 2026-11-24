<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryCollection;
use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;

final class AdminSummaryPresentation
{
	public function build(array $user, SummaryCollection $summaries, string $region): array
	{
		$region = strtoupper($region);
		$thresholds = RegionThresholdsFactory::create($region);
		$hasRegionalRules = $thresholds->isSupported();
		$savedByCode = $summaries->getByCodeMap();
		$thresholdsByCode = [];
		$ordersByCode = [];
		$catalog = new MetricCatalog();
		$metrics = [];
		$knownCodes = [];
		foreach ($catalog->getMetrics() as $definition)
		{
			$code = $definition['code'];
			$knownCodes[$code] = true;
			$threshold = $thresholds->getThreshold($code);
			$ordersByCode[$code] = $catalog->getOrder($code);
			if ($threshold !== null)
			{
				$thresholdsByCode[$code] = $threshold;
			}
			$metric = $this->createMetric($code, $definition['name'], $savedByCode[$code] ?? null);
			if ($metric['present'] && $threshold !== null && $threshold->getMax() > 0)
			{
				$coefficient = $savedByCode[$code]->getCoefficient($threshold);
				$metric['isSuper'] = $coefficient->isSuper();
				$metric['coefficient'] = [
					'total' => $coefficient->getTotal(),
					'max' => $coefficient->getMax(),
					'displayValue' => $coefficient->format(),
				];
			}

			$details = [];
			foreach ($definition['details'] as $detailDefinition)
			{
				$detailCode = $detailDefinition['code'];
				$knownCodes[$detailCode] = true;
				$details[] = $this->createMetric(
					$detailCode,
					$detailDefinition['name'],
					$savedByCode[$detailCode] ?? null,
				);
			}

			if ($hasRegionalRules)
			{
				$metric['details'] = $details;
			}

			$metrics[] = $metric;
			if (!$hasRegionalRules)
			{
				array_push($metrics, ...$details);
			}
		}

		$unknownCodes = array_diff(array_keys($savedByCode), array_keys($knownCodes));
		sort($unknownCodes, SORT_STRING);
		foreach ($unknownCodes as $code)
		{
			$metrics[] = $this->createMetric((string)$code, (string)$code, $savedByCode[$code]);
		}

		$winners = $summaries->withRanking($thresholdsByCode, $ordersByCode)->getTop();

		return [
			'user' => [
				'id' => (int)$user['ID'],
				'name' => \CUser::FormatName(\CSite::GetNameFormat(), $user, true, false),
			],
			'region' => $region,
			'metrics' => $metrics,
			'winnerCodes' => array_keys($winners->getByCodeMap()),
		];
	}

	private function createMetric(string $code, string $name, ?SummaryInterface $saved): array
	{
		return [
			'code' => $code,
			'name' => $name,
			'present' => $saved !== null,
			'total' => $saved?->getTotal(),
			'isSuper' => false,
			'coefficient' => null,
			'details' => [],
		];
	}
}
