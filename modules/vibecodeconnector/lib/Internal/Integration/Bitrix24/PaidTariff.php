<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24;

use Bitrix\Main;
use Bitrix\Main\Loader;

class PaidTariff
{
	private Main\License $license;
	private VibeDemoGateway $vibeDemoGateway;

	public function __construct(?Main\License $license = null, ?VibeDemoGateway $vibeDemoGateway = null)
	{
		$this->license = $license ?? Main\Application::getInstance()->getLicense();
		$this->vibeDemoGateway = $vibeDemoGateway ?? new VibeDemoGateway();
	}

	public function isActive(): bool
	{
		if (Loader::includeModule('bitrix24'))
		{
			return $this->isCloudLicensePaid() || $this->isVibeDemoActive();
		}

		return !$this->license->isDemoKey();
	}

	public function isCloudLicensePaid(): bool
	{
		return \CBitrix24::isLicensePaid() || \CBitrix24::IsNfrLicense() || \CBitrix24::IsDemoLicense();
	}

	private function isVibeDemoActive(): bool
	{
		$state = $this->vibeDemoGateway->getState();
		$expireDate = $state->getData()['expireDate'] ?? null;

		return $state->isSuccess() && $expireDate !== null && strtotime($expireDate) > time();
	}
}
