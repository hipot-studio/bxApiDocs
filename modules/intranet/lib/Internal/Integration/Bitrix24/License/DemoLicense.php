<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\License;

use Bitrix\Main\Loader;

class DemoLicense
{
	private bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function isActive(): bool
	{
		if (!$this->isModuleIncluded)
		{
			return false;
		}

		return \Bitrix\Bitrix24\License\DemoLicense::getCurrent()->isActive();
	}
}