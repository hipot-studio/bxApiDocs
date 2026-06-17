<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfo;
use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoRepository;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;

class SupersetDashboardInfoProvider
{
	public function __construct(private readonly SupersetDashboardInfoRepository $repository)
	{
	}

	/**
	 * @param int $id
	 * @return SupersetDashboardInfo|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getById(int $id): ?SupersetDashboardInfo
	{
		return $this->repository->getById($id);
	}

	/**
	 * @param int $dashboardId
	 * @return SupersetDashboardInfo|null
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardId(int $dashboardId): ?SupersetDashboardInfo
	{
		return $this->repository->getByDashboardId($dashboardId);
	}
}
