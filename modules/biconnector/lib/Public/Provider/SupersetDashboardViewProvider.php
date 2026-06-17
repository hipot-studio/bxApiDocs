<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardViewRepository;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;

class SupersetDashboardViewProvider
{
	public function __construct(private readonly SupersetDashboardViewRepository $repository)
	{
	}

	/**
	 * @param int $dashboardId
	 * @return int
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function countViews(int $dashboardId): int
	{
		return $this->repository->countViews($dashboardId);
	}
}
