<?php

namespace Bitrix\BIConnector\Superset\Dashboard\Attach;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Integration\Superset\Integrator\Dto;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;

final class AttachAccessChecker
{
	public static function canAttachAnyDashboard(): bool
	{
		return AccessController::getCurrent()->getUser()->isAdmin();
	}

	public static function canAttach(int $externalId): bool
	{
		if ($externalId <= 0)
		{
			return false;
		}

		if (self::canAttachAnyDashboard())
		{
			return true;
		}

		$response = IntegratorFactory::getInstance()->getDashboardListByFilter([
			'ids' => [$externalId],
			'ownedByRequestUser' => true,
		]);
		if ($response->hasErrors())
		{
			return false;
		}

		$dashboardList = $response->getData();
		if (!$dashboardList instanceof Dto\DashboardList)
		{
			return false;
		}

		return in_array($externalId, $dashboardList->collectId(), true);
	}
}
