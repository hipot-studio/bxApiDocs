<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Service\Otp\DeviceReconnect;
use Bitrix\Main\UserAuthActionTable;

class LogoutService
{
	public function __construct(
		private readonly User $user,
	) {
	}

	public function logoutAll(): void
	{
		$this->setDeviceLostFlag();
		(new ApplicationPasswordService())->removeAllByUserId((int)$this->user->getId());
		$this->addLogoutAction();
	}

	public function logoutAllExceptTrustedDevice(): void
	{
		$service = new ApplicationPasswordService();
		$deviceCode = $this->getTrustedDeviceCode();

		if ($deviceCode !== null)
		{
			$service->removeAllByUserIdExceptDevice((int)$this->user->getId(), $deviceCode);
		}
		else
		{
			$this->setDeviceLostFlag();
			$service->removeAllByUserId((int)$this->user->getId());
		}

		$this->addLogoutAction();
	}

	private function addLogoutAction(): void
	{
		if ($this->user->isCurrent())
		{
			global $USER;
			$USER->SetParam("AUTH_ACTION_SKIP_LOGOUT", true);
		}

		UserAuthActionTable::addLogoutAction($this->user->getId());
	}

	private function getTrustedDeviceCode(): ?string
	{
		$personalOtp = (new OtpSettings())->getPersonalSettingsByUserId((int)$this->user->getId());
		if ($personalOtp === null)
		{
			return null;
		}

		$initParams = $personalOtp->getInitParams();

		return $initParams['deviceInfo']['id'] ?? null;
	}

	private function setDeviceLostFlag(): void
	{
		$personalOtp = (new OtpSettings())->getPersonalSettingsByUserId((int)$this->user->getId());
		if ($personalOtp?->isPushType())
		{
			(new DeviceReconnect())->markLost();
		}
	}
}
