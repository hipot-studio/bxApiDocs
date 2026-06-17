<?php

namespace Bitrix\BIConnector\Public\Command\DashboardView;

use Bitrix\Main\Result;
use Bitrix\BIConnector\Internal\Entity\SupersetDashboardView;

class SupersetDashboardViewResult extends Result
{
	private ?SupersetDashboardView $dashboardView;

	public function __construct(?SupersetDashboardView $dashboardView = null)
	{
		parent::__construct();
		$this->dashboardView = $dashboardView;
	}

	public function getDashboardView(): ?SupersetDashboardView
	{
		return $this->dashboardView;
	}

	public function setDashboardView(?SupersetDashboardView $dashboardView): self
	{
		$this->dashboardView = $dashboardView;

		return $this;
	}
}
