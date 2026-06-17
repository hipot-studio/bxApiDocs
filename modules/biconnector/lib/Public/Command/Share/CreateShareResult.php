<?php

namespace Bitrix\BIConnector\Public\Command\Share;

use Bitrix\BIConnector\Internal\Entity\SupersetDashboardShare;
use Bitrix\Main\Result;

class CreateShareResult extends Result
{
	private ?SupersetDashboardShare $share = null;

	public function getShare(): ?SupersetDashboardShare
	{
		return $this->share;
	}

	public function setShare(?SupersetDashboardShare $share): self
	{
		$this->share = $share;

		return $this;
	}
}
