<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Chart\ChartAuthorAccess;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\DashboardGuard;
use Bitrix\BIConnector\Internal\Operation\Builder\Dashboard\SetContentOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\BIConnector\Internal\Operation\Builder\SupersetIdentity;
use Bitrix\Main\Result;

final class ChartAdmission
{
	private readonly DashboardGuard $guard;
	private readonly SupersetIdentity $identity;

	public function __construct(?DashboardGuard $guard = null, ?SupersetIdentity $identity = null)
	{
		$this->guard = $guard ?? new DashboardGuard();
		$this->identity = $identity ?? new SupersetIdentity();
	}

	/** @param list<int> $chartIds */
	public function admit(array $chartIds, Actor $actor): Result
	{
		if ($chartIds === [])
		{
			return new Result();
		}
		$identityResult = $this->identity->forRead($actor->userId);
		if (!$identityResult->isSuccess())
		{
			return $identityResult;
		}

		$integrator = IntegratorFactory::getInstance();
		$authorAccess = new ChartAuthorAccess();
		foreach (array_unique($chartIds) as $chartId)
		{
			$response = $integrator->getChartById($chartId, $identityResult->getData()['user']);
			if (in_array($response->getStatus(), [
				IntegratorResponse::STATUS_NOT_FOUND,
				IntegratorResponse::STATUS_NO_ACCESS,
			], true))
			{
				return $this->refuse('chart_not_accessible');
			}
			if ($response->hasErrors() || $response->getStatus() >= 400)
			{
				return $this->unavailable($response, $actor, $chartId);
			}

			$chart = $response->getData();
			if (
				!is_array($chart)
				|| !is_array($chart['dashboards'] ?? null)
				|| !array_is_list($chart['dashboards'])
			)
			{
				return $this->refuse('chart_not_accessible');
			}

			$accessible = $chart['dashboards'] === []
				? $authorAccess->mayManageOrphan(
					is_array($chart['owners'] ?? null) ? $chart['owners'] : [], $actor,
				)
				: $this->hasEditableDashboard($chart['dashboards'], $actor);
			if (!$accessible)
			{
				return $this->refuse('chart_not_accessible');
			}
		}

		return new Result();
	}

	/**
	 * @param list<int> $chartIds
	 * @param list<int> $layoutChartIds
	 */
	public function checkInLayout(array $chartIds, array $layoutChartIds, array $jsonMetadata): Result
	{
		$existing = array_fill_keys($layoutChartIds, true);
		foreach ($chartIds as $chartId)
		{
			if (isset($existing[$chartId]))
			{
				return $this->refuse('chart_already_in_layout');
			}
			$existing[$chartId] = true;
		}
		if (!DashboardJsonMetadataReader::hasTimeRange($jsonMetadata))
		{
			return $this->refuse('no_time_range');
		}

		return new Result();
	}

	/**
	 * @param list<int> $oldIds
	 * @param list<int> $newIds
	 */
	public function rescope(array $jsonMetadata, array $oldIds, array $newIds): array
	{
		$config = $jsonMetadata['native_filter_configuration'] ?? null;
		if (is_array($config))
		{
			$jsonMetadata['native_filter_configuration'] = SetContentOperation::rescopeChartFilters(
				$config,
				$oldIds,
				$newIds,
			);
		}

		return $jsonMetadata;
	}

	private function hasEditableDashboard(array $dashboards, Actor $actor): bool
	{
		$externalIds = [];
		foreach ($dashboards as $dashboard)
		{
			$rawId = is_array($dashboard) ? ($dashboard['id'] ?? null) : null;
			$id = is_int($rawId) || is_string($rawId) ? (int)$rawId : 0;
			if ($id > 0 && (string)$id === (string)$rawId)
			{
				$externalIds[$id] = $id;
			}
		}
		$mappedChunks = function () use ($externalIds): \Generator {
			foreach (array_chunk(array_values($externalIds), 300) as $chunk)
			{
				yield $this->getMappedIds($chunk);
			}
		};

		return $this->guard->hasEditableCandidates($mappedChunks(), $actor);
	}

	/** @return \Generator<int> */
	private function getMappedIds(array $chunk): \Generator
	{
		$rows = SupersetDashboardTable::query()->setSelect(['ID', 'EXTERNAL_ID'])->whereIn('EXTERNAL_ID', $chunk)->exec();
		$matches = [];
		while ($row = $rows->fetch())
		{
			$matches[(int)$row['EXTERNAL_ID']][] = (int)$row['ID'];
		}
		foreach ($matches as $ids)
		{
			if (count($ids) === 1)
			{
				yield $ids[0];
			}
		}
	}

	private function unavailable(IntegratorResponse $response, Actor $actor, int $chartId): Result
	{
		return Refusal::unavailableBi($response->getErrors(), [
			'stage' => 'layout.chart_admission',
			'user_id' => $actor->userId,
			'chart_id' => $chartId,
		])->setData(['reason' => 'superset_unavailable']);
	}

	private function refuse(string $reason): Result
	{
		$message = match ($reason)
		{
			'chart_already_in_layout' => 'The chart is already in the dashboard layout.',
			'no_time_range' => 'The dashboard requires a time_range filter before adding charts.',
			default => 'The chart does not exist or is not accessible.',
		};

		return Refusal::error(FailureCode::InvalidArgument, $message)->setData(['reason' => $reason]);
	}
}
