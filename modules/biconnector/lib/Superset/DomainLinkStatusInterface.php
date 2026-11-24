<?php

namespace Bitrix\BIConnector\Superset;

use Bitrix\Main\Result;

interface DomainLinkStatusInterface
{
	public function isLinked(): bool;

	public function clearUnlinkedStatus(): void;

	public function setUnlinked(): void;

	public function linkAddress(): Result;
}
