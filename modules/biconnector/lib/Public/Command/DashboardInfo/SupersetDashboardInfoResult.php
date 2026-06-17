<?php

namespace Bitrix\BIConnector\Public\Command\DashboardInfo;

use Bitrix\Main\Result;
use Bitrix\BIConnector\Internal\Entity\SupersetDashboardInfo;

class SupersetDashboardInfoResult extends Result
{
	private ?SupersetDashboardInfo $dashboardInfo;

	public function __construct(?SupersetDashboardInfo $dashboardInfo = null)
	{
		parent::__construct();
		$this->dashboardInfo = $dashboardInfo;
	}

	public function getDashboardInfo(): ?SupersetDashboardInfo
	{
		return $this->dashboardInfo;
	}

	public function setDashboardInfo(?SupersetDashboardInfo $dashboardInfo): self
	{
		$this->dashboardInfo = $dashboardInfo;

		return $this;
	}
}
