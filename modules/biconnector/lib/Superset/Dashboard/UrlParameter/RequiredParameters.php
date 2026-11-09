<?php

namespace Bitrix\BIConnector\Superset\Dashboard\UrlParameter;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Superset\Dashboard\EmbeddedFilter;

/**
 * Parameters a dashboard needs to keep its data within the user's scope.
 * `current_user` is needed by the company structure native filter only: without the parameter the dataset
 * of that filter returns the whole company structure.
 * Personal system dashboards need it regardless of their filters: they show the data of the viewer only.
 */
final class RequiredParameters
{
	/**
	 * Without `current_user` they show no data or duplicate a non-personal system dashboard.
	 * Their copies are custom dashboards and follow the general rule.
	 */
	private const PERSONAL_SYSTEM_APP_CODES = [
		'bic_perkpi',
		'bic_taskeff_param',
		'bic_throughput',
	];

	/**
	 * @return array<string, string>|null Parameter code => hint, in the format of ScopeMap::getRequiredParamList();
	 *     null when the dashboard data was not loaded from Superset and the list depends on it.
	 */
	public static function getList(Dashboard $dashboard): ?array
	{
		if (self::isPersonalSystemDashboard($dashboard))
		{
			return ScopeMap::getRequiredParamList();
		}

		if (!$dashboard->isSupersetDashboardDataLoaded())
		{
			return null;
		}

		return self::hasCompanyStructureFilter($dashboard->getNativeFiltersConfig())
			? ScopeMap::getRequiredParamList()
			: []
		;
	}

	private static function isPersonalSystemDashboard(Dashboard $dashboard): bool
	{
		if (!$dashboard->isSystemDashboard())
		{
			return false;
		}

		// The vendor prefix depends on the region: bitrix.bic_throughput, alaio.bic_throughput.
		$appCode = explode('.', $dashboard->getAppId(), 2)[1] ?? '';

		return in_array($appCode, self::PERSONAL_SYSTEM_APP_CODES, true);
	}

	private static function hasCompanyStructureFilter(array $nativeFilterConfig): bool
	{
		$filterType = EmbeddedFilter\CurrentUser::getFilterType();
		foreach ($nativeFilterConfig as $filter)
		{
			if (is_array($filter) && ($filter['filterType'] ?? null) === $filterType)
			{
				return true;
			}
		}

		return false;
	}
}
