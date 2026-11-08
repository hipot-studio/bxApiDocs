<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Digest\Service;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\Main\Web\Json;

final class DigestSetupDashboardGuard
{
	/**
	 * Upper bound of the report screen state the setup accepts, as encoded JSON. A real screen carries a few
	 * native filters with short values; anything larger is not a screen of a report.
	 */
	public const MAX_SCREEN_STATE_BYTES = 16384;
	public const MAX_SCREEN_STATE_FILTERS = 50;

	private const NATIVE_FILTER_ID_PATTERN = '/^NATIVE_FILTER-[A-Za-z0-9_-]{1,64}$/';
	private const SCREEN_STATE_KEYS = ['extraFormData', 'filterState'];

	private readonly \Closure $findDashboard;
	private readonly \Closure $canView;

	public function __construct(?\Closure $findDashboard = null, ?\Closure $canView = null)
	{
		$this->findDashboard = $findDashboard ?? static fn(int $dashboardId): ?Dashboard =>
			(new SupersetController())->getDashboardRepository()->getById($dashboardId);
		$this->canView = $canView ?? static fn(int $userId, Dashboard $dashboard): bool =>
			AccessController::getInstance($userId)->check(
				ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
				DashboardAccessItem::createFromEntity($dashboard),
			);
	}

	public function resolve(?int $dashboardId, int $userId): ?Dashboard
	{
		if ($dashboardId === null)
		{
			return null;
		}

		if ($dashboardId <= 0 || $userId <= 0)
		{
			return null;
		}

		$dashboard = ($this->findDashboard)($dashboardId);
		if (!$dashboard instanceof Dashboard)
		{
			return null;
		}

		if (
			!in_array(
				$dashboard->getStatus(),
				[
					SupersetDashboardTable::DASHBOARD_STATUS_READY,
					SupersetDashboardTable::DASHBOARD_STATUS_DRAFT,
				],
				true,
			)
			|| ($dashboard->getExternalId() ?? 0) <= 0
			|| !($this->canView)($userId, $dashboard)
		)
		{
			return null;
		}

		return $dashboard;
	}

	/**
	 * Narrows the screen state sent by the browser to the entries of native filters and the parts of them
	 * the interpretation reads. A state that is not a map or exceeds the bounds is refused as a whole.
	 *
	 * @return array<string, array>|null Native filter id => its state; null when nothing is left to read.
	 */
	public function acceptScreenState(mixed $screenState): ?array
	{
		if (!is_array($screenState) || $screenState === [] || count($screenState) > self::MAX_SCREEN_STATE_FILTERS)
		{
			return null;
		}

		try
		{
			$encoded = Json::encode($screenState);
		}
		catch (\Throwable)
		{
			return null;
		}

		if (strlen($encoded) > self::MAX_SCREEN_STATE_BYTES)
		{
			return null;
		}

		$accepted = [];
		foreach ($screenState as $filterId => $state)
		{
			if (!is_string($filterId) || !preg_match(self::NATIVE_FILTER_ID_PATTERN, $filterId) || !is_array($state))
			{
				continue;
			}

			$parts = array_intersect_key($state, array_flip(self::SCREEN_STATE_KEYS));
			if ($parts !== [])
			{
				$accepted[$filterId] = $parts;
			}
		}

		return $accepted === [] ? null : $accepted;
	}
}
