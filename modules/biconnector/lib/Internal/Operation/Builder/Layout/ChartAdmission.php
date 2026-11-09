<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Layout;

use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetUserTable;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Dashboard\DashboardJsonMetadataReader;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
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
		foreach (array_unique($chartIds) as $chartId)
		{
			$response = $integrator->getChartById($chartId, $identityResult->getData()['user']);
			$reason = (string)($response->getErrors()[0] ?? null)?->getCode();
			if ($reason === 'ai_tools_disabled')
			{
				return $this->unavailable($response, $actor, $chartId, $reason);
			}
			if (in_array($response->getStatus(), [
				IntegratorResponse::STATUS_NOT_FOUND,
				IntegratorResponse::STATUS_NO_ACCESS,
			], true))
			{
				return $this->refuse('chart_not_accessible');
			}
			if ($response->hasErrors() || $response->getStatus() >= 400)
			{
				return $this->unavailable($response, $actor, $chartId, $reason ?: 'superset_unavailable');
			}

			$chart = $response->getData();
			if (
				!is_array($chart)
				|| !is_array($chart['dashboards'] ?? null)
				|| !array_is_list($chart['dashboards'])
				|| !is_array($chart['owners'] ?? null)
				|| !array_is_list($chart['owners'])
			)
			{
				return $this->refuse('chart_not_accessible');
			}

			$accessible = $chart['dashboards'] === []
				? $this->isOwner($chart['owners'], $actor)
				: $this->hasViewableDashboard($chart['dashboards'], $actor);
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

	private function hasViewableDashboard(array $dashboards, Actor $actor): bool
	{
		$externalIds = [];
		foreach ($dashboards as $dashboard)
		{
			$id = is_array($dashboard) ? (int)($dashboard['id'] ?? 0) : 0;
			if ($id > 0)
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

		return $this->guard->hasViewableCandidates($mappedChunks(), $actor);
	}

	/** @return \Generator<int> */
	private function getMappedIds(array $chunk): \Generator
	{
		$rows = SupersetDashboardTable::query()->setSelect(['ID'])->whereIn('EXTERNAL_ID', $chunk)->exec();
		while ($row = $rows->fetch())
		{
			yield (int)$row['ID'];
		}
	}

	private function isOwner(array $owners, Actor $actor): bool
	{
		$row = SupersetUserTable::query()
			->setSelect(['CLIENT_ID'])
			->where('USER_ID', $actor->userId)
			->setLimit(1)
			->fetch()
		;
		$clientId = (string)($row['CLIENT_ID'] ?? '');

		return $clientId !== '' && in_array($clientId, $owners, true);
	}

	private function unavailable(IntegratorResponse $response, Actor $actor, int $chartId, string $reason): Result
	{
		return Refusal::unavailableBi($response->getErrors(), [
			'stage' => 'layout.chart_admission',
			'user_id' => $actor->userId,
			'chart_id' => $chartId,
		])->setData(['reason' => $reason]);
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
