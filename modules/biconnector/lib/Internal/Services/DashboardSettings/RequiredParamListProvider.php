<?php

namespace Bitrix\BIConnector\Internal\Services\DashboardSettings;

use Bitrix\BIConnector\Integration\Superset\Model\Dashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\RequiredParameters;

/**
 * Required parameters for the dashboard settings forms. The forms load dashboards without Superset data,
 * so the card is read here; a failed read is not arbitrated: opening a form must not move the instance to ERROR.
 */
class RequiredParamListProvider
{
	/**
	 * Read timeout of the dashboard card in seconds: a form does not wait for Superset longer.
	 * Applies to the cloud proxy: the self-hosted integrator does not take a timeout.
	 */
	private const REMOTE_READ_TIMEOUT = 5;

	/**
	 * The dashboard of a form carries no Superset data, so the rule answers on it only when the list
	 * does not depend on the filters; otherwise the card is read.
	 *
	 * @return array<string, string>|null See getByExternalId().
	 */
	public function getForDashboard(Dashboard $dashboard): ?array
	{
		return RequiredParameters::getList($dashboard)
			?? $this->getByExternalId((int)$dashboard->getExternalId())
		;
	}

	/**
	 * Superset data is read into a separate model, so a form keeps the title saved on the portal.
	 *
	 * @return array<string, string>|null In the format of RequiredParameters::getList();
	 *     null when the filters of the dashboard could not be read: nothing is imposed then.
	 */
	public function getByExternalId(int $externalId): ?array
	{
		if ($externalId <= 0 || !SupersetInitializer::isSupersetReady())
		{
			return null;
		}

		$dashboard = new Dashboard(SupersetDashboardTable::createObject()->setExternalId($externalId));
		$dashboard->loadProxyData(self::REMOTE_READ_TIMEOUT, arbitrateServiceFailure: false);

		return RequiredParameters::getList($dashboard);
	}
}
