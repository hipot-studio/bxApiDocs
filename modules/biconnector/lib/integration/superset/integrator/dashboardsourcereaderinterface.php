<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;

interface DashboardSourceReaderInterface
{
	/**
	 * Returns the raw layout source without decoding nested JSON fields.
	 *
	 * @param int $dashboardId
	 * @return IntegratorResponse<Dto\DashboardSource|null>
	 */
	public function getDashboardSourceById(int $dashboardId): IntegratorResponse;
}
