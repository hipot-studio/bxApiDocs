<?php

namespace Bitrix\BIConnector\Public\Command\DashboardChat;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardChat;
use Bitrix\Main\Result;

class SupersetDashboardChatResult extends Result
{
	private ?SupersetDashboardChat $dashboardChat;

	public function __construct(?SupersetDashboardChat $dashboardChat = null)
	{
		parent::__construct();
		$this->dashboardChat = $dashboardChat;
	}

	public function getDashboardChat(): ?SupersetDashboardChat
	{
		return $this->dashboardChat;
	}

	public function setDashboardChat(?SupersetDashboardChat $dashboardChat): self
	{
		$this->dashboardChat = $dashboardChat;

		return $this;
	}
}
