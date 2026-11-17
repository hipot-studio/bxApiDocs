<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Integration\Main;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;

class TrustedDeviceBindingProvider
{
	public function __construct(
		private readonly User $user,
		private ?PersonalOtp $personalOtp = null,
		private ?ApplicationPasswordService $applicationPasswordService = null,
	) {
	}

	public function isBound(): bool
	{
		$deviceCode = $this->getDeviceCode();

		if ($deviceCode === null)
		{
			return false;
		}

		return $this->getApplicationPasswordService()
			->hasMobilePasswordByCode((int)$this->user->getId(), $deviceCode)
		;
	}

	private function getDeviceCode(): ?string
	{
		if (!$this->getPersonalOtp()->hasConnectedPushDevice())
		{
			return null;
		}

		return $this->getDeviceInfoString('id');
	}

	private function getDeviceInfoString(string $key): ?string
	{
		$deviceInfo = $this->getPersonalOtp()->getInitParams()['deviceInfo'] ?? [];

		if (!is_array($deviceInfo))
		{
			return null;
		}

		$value = $deviceInfo[$key] ?? null;

		return is_string($value) && $value !== '' ? $value : null;
	}

	private function getPersonalOtp(): PersonalOtp
	{
		return $this->personalOtp ??= new PersonalOtp($this->user);
	}

	private function getApplicationPasswordService(): ApplicationPasswordService
	{
		return $this->applicationPasswordService ??= new ApplicationPasswordService();
	}
}
