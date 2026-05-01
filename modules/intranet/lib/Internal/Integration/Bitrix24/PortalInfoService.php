<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24;

use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class PortalInfoService
{
	private bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function getCreationDateTime(): ?DateTime
	{
		if (!$this->isModuleIncluded)
		{
			return null;
		}

		$portalCreationTimestamp = (int)\CBitrix24::getCreateTime();

		if ($portalCreationTimestamp <= 0)
		{
			return null;
		}

		return DateTime::createFromTimestamp($portalCreationTimestamp);
	}
}
