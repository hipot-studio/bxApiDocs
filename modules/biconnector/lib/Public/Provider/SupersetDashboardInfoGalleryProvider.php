<?php

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Internal\Repository\SupersetDashboardInfoGalleryRepository;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ORM\Objectify\Collection;

class SupersetDashboardInfoGalleryProvider
{
	public function __construct(private readonly SupersetDashboardInfoGalleryRepository $repository)
	{
	}

	/**
	 * @param int $dashboardInfoId
	 * @return Collection
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getByDashboardInfoId(int $dashboardInfoId): Collection
	{
		return $this->repository->getByDashboardInfoId($dashboardInfoId);
	}
}
