<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Integration\Superset\Integrator\DashboardSourceReaderInterface;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto\DashboardSource;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardLayoutBuilder;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

final class LayoutSource
{
	public const MAX_SOURCE_BYTES = 1048576;

	public function read(int $externalId, Actor $actor, string $stage): Result
	{
		$integrator = IntegratorFactory::getInstance();
		if (!$integrator instanceof DashboardSourceReaderInterface)
		{
			return Refusal::error(FailureCode::Unavailable, 'Dashboard source is unavailable.');
		}

		$response = $integrator->getDashboardSourceById($externalId);
		$context = [
			'stage' => $stage,
			'user_id' => $actor->userId,
			'external_id' => $externalId,
		];
		if ($response->hasErrors())
		{
			return Refusal::unavailableDashboard($response->getErrors(), $context);
		}

		$dashboard = $response->getData();
		if (!$dashboard instanceof DashboardSource)
		{
			return Refusal::error(FailureCode::Unavailable, 'Dashboard source is unavailable.');
		}
		if ($dashboard->chartIds !== null && !DashboardSource::isValidChartIds($dashboard->chartIds))
		{
			return Refusal::error(FailureCode::Unavailable, 'Dashboard chart membership is unavailable.');
		}

		foreach ([$dashboard->positionJson, $dashboard->jsonMetadataSource] as $source)
		{
			if ($source !== null && strlen($source) > self::MAX_SOURCE_BYTES)
			{
				return Refusal::error(FailureCode::DomainRule, 'Dashboard source exceeds the byte limit.')
					->setData(['limit' => self::MAX_SOURCE_BYTES, 'value' => strlen($source)])
				;
			}
		}
		if ($dashboard->positionJson === null || $dashboard->jsonMetadataSource === null)
		{
			return Refusal::error(FailureCode::Unavailable, 'Dashboard source fields are unavailable.');
		}

		try
		{
			$jsonMetadata = $this->decodeObject($dashboard->jsonMetadataSource);
			$positions = $this->decodeObject($dashboard->positionJson);
		}
		catch (\Exception)
		{
			return Refusal::error(FailureCode::DomainRule, 'Dashboard source must contain valid JSON objects.')
				->setData(['rule' => 'broken_source'])
			;
		}

		if ($dashboard->positionJson === '')
		{
			$positions = DashboardLayoutBuilder::build([]);
		}

		return (new Result())->setData([
			'positions' => $positions,
			'jsonMetadata' => $jsonMetadata,
			'published' => $dashboard->published,
			'chartIds' => $dashboard->chartIds,
		]);
	}

	/** @param list<int>|null $chartIds */
	public static function getMembershipProblem(DashboardLayout $layout, ?array $chartIds): ?string
	{
		if ($chartIds === null)
		{
			return 'chart_membership_unknown';
		}

		$positionChartIds = [];
		foreach ($layout->nodes() as $node)
		{
			$chartId = $node['meta']['chartId'] ?? null;
			if (($node['type'] ?? null) === 'CHART' && is_int($chartId) && $chartId > 0)
			{
				$positionChartIds[$chartId] = true;
			}
		}
		foreach ($chartIds as $chartId)
		{
			if (!isset($positionChartIds[$chartId]))
			{
				return 'incomplete_layout';
			}
		}

		return null;
	}

	private function decodeObject(string $source): array
	{
		if ($source === '')
		{
			return [];
		}

		$decoded = Json::decode($source);
		if (!is_array($decoded) || !str_starts_with(ltrim($source), '{'))
		{
			throw new \UnexpectedValueException('Expected a JSON object.');
		}

		return $decoded;
	}
}
