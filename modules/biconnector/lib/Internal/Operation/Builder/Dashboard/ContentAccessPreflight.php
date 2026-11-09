<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder\Dashboard;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\EntityLookup;
use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;
use Bitrix\BIConnector\Internal\Operation\Builder\Refusal;
use Bitrix\Main\Result;

final class ContentAccessPreflight
{
	public function check(array $chartIds, Actor $actor, array $savedChartIds = []): Result
	{
		$uniqueIds = [];
		foreach ($chartIds as $id)
		{
			if (!self::isPositiveId($id))
			{
				return Refusal::error(FailureCode::InvalidArgument, 'chartId must be a positive integer.');
			}
			$uniqueIds[(int)$id] = (int)$id;
		}

		// Charts already in the target's saved layout are allowed without reading them.
		$saved = [];
		foreach ($savedChartIds as $savedId)
		{
			$savedId = is_scalar($savedId) ? (int)$savedId : 0;
			if ($savedId > 0)
			{
				$saved[$savedId] = true;
			}
		}
		$uniqueIds = array_diff_key($uniqueIds, $saved);
		if ($uniqueIds === [])
		{
			return new Result();
		}

		$lookup = new EntityLookup();
		$charts = [];
		$externalIds = [];
		foreach ($uniqueIds as $chartId)
		{
			$loaded = $lookup->chart($chartId);
			if (!$loaded->isSuccess())
			{
				return $loaded;
			}

			$consumers = $loaded->getData()['entity']['dashboards'] ?? null;
			if (!is_array($consumers))
			{
				return Refusal::unavailableBi([], ['stage' => 'content_preflight.consumers', 'chart_id' => $chartId]);
			}

			$ids = [];
			$broken = false;
			foreach ($consumers as $consumer)
			{
				$id = is_array($consumer) ? ($consumer['id'] ?? null) : null;
				if (!self::isPositiveId($id))
				{
					$broken = true;

					continue;
				}
				$ids[(int)$id] = (int)$id;
				$externalIds[(int)$id] = (int)$id;
			}
			$charts[$chartId] = ['ids' => $ids, 'broken' => $broken];
		}

		$dashboards = [];
		foreach (array_chunk(array_values($externalIds), 300) as $chunk)
		{
			$rows = SupersetDashboardTable::query()
				->setSelect(['ID', 'EXTERNAL_ID', 'TYPE', 'STATUS'])
				->whereIn('EXTERNAL_ID', $chunk)
				->exec()
			;
			while ($row = $rows->fetch())
			{
				$dashboards[(int)$row['EXTERNAL_ID']][] = $row;
			}
		}

		$controller = new AccessController($actor->userId);
		$editable = [];
		foreach ($charts as $chartId => $chart)
		{
			if ($chart['ids'] === [] && !$chart['broken'])
			{
				continue;
			}

			$allowed = false;
			foreach ($chart['ids'] as $externalId)
			{
				$matches = $dashboards[$externalId] ?? [];
				if (count($matches) !== 1)
				{
					continue;
				}

				$editable[$externalId] ??= $controller->check(
					ActionDictionary::ACTION_BIC_DASHBOARD_EDIT,
					DashboardAccessItem::createFromArray($matches[0]),
				);
				if ($editable[$externalId])
				{
					$allowed = true;

					break;
				}
			}
			if (!$allowed)
			{
				return $chart['broken']
					? Refusal::unavailableBi([], ['stage' => 'content_preflight.consumers', 'chart_id' => $chartId])
					: Refusal::error(FailureCode::AccessDenied, 'Access denied.');
			}
		}

		return new Result();
	}

	private static function isPositiveId(mixed $id): bool
	{
		return is_int($id)
			? $id > 0
			: is_string($id)
				&& preg_match('/^[1-9][0-9]*$/D', $id) === 1
				&& (string)(int)$id === $id;
	}
}
