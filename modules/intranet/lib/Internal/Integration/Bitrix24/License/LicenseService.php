<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Bitrix24\License;

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

class LicenseService
{
	private bool $isModuleIncluded;

	public function __construct()
	{
		$this->isModuleIncluded = Loader::includeModule('bitrix24');
	}

	public function isCloud(): bool
	{
		return $this->isModuleIncluded;
	}

	public function isFree(): bool
	{
		if ($this->isModuleIncluded)
		{
			return \CBitrix24::isFreeLicense();
		}

		$license = Application::getInstance()->getLicense();

		return !$license->isTimeBound() && !$license->isDemo();
	}
}
