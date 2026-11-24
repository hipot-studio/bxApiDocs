<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Public\Provider\Portal\DomainProvider;
use Bitrix\Security\Mfa\Otp;
use Closure;

class CurrentOtpReader
{
	public function __construct(
		private ?OtpSettings $settings = null,
		private readonly ?Closure $otpLoader = null,
		private readonly ?DomainProvider $domainProvider = null,
	)
	{
	}

	public function getProvisioningUri(User $user): string
	{
		$settings = $this->settings ??= new OtpSettings();
		if (!$settings->isAvailable() || !$settings->isEnabled())
		{
			throw new \RuntimeException('Current OTP is unavailable.');
		}

		$otp = $this->otpLoader !== null
			? ($this->otpLoader)($user->getId())
			: Otp::getByUser($user->getId());
		if (!$this->isProvisioningCompatible($otp))
		{
			throw new \RuntimeException('Current OTP is unavailable.');
		}

		$host = ($this->domainProvider ?? new DomainProvider())->getHostName();
		if ($host === '')
		{
			throw new \RuntimeException('Current OTP is unavailable.');
		}

		$export = clone $otp;
		$export->setIssuer($host);
		$export->setLabel($host . ':' . $export->getUserLogin());
		$options = $export->getType() === \Bitrix\Security\Mfa\OtpType::Hotp
			? ['counter' => (int)$export->getParams()]
			: [];

		return $export->getProvisioningUri($options);
	}

	public function isAvailable(User $user): bool
	{
		$settings = $this->settings ??= new OtpSettings();
		if (!$settings->isAvailable() || !$settings->isEnabled())
		{
			return false;
		}

		$otp = $this->otpLoader !== null
			? ($this->otpLoader)($user->getId())
			: Otp::getByUser($user->getId());

		return $this->isProvisioningCompatible($otp);
	}

	private function isProvisioningCompatible(Otp $otp): bool
	{
		if (!$otp->isActivated())
		{
			return false;
		}

		$secret = $otp->getSecret();
		if (!is_string($secret) || $secret === '')
		{
			return false;
		}

		$isTimeBased = in_array(
			$otp->getType(),
			[
				\Bitrix\Security\Mfa\OtpType::Totp,
				\Bitrix\Security\Mfa\OtpType::Push,
			],
			true,
		);

		return !$isTimeBased || (int)($otp->getInitParams()['startTimestamp'] ?? 0) <= 0;
	}
}
