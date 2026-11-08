<?php

declare(strict_types = 1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Entity\User;
use Bitrix\Intranet\Internal\Integration\Security\OtpSettings;
use Bitrix\Intranet\Internal\Integration\Security\PersonalOtp;
use Bitrix\Intranet\Internal\Integration\Security\PushOtpSecretMatcher;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Decides whether the caller's local Push OTP generator still matches the
 * current secret of the portal. An undefined outcome is always an error,
 * never isTrusted: clients erase the local secret on a negative answer.
 */
class TrustedDeviceCheckService
{
	public const ERROR_UNAVAILABLE = 'OTP_TRUST_CHECK_UNAVAILABLE';

	/** @var callable(User): PersonalOtp */
	private $personalOtpFactory;

	public function __construct(
		private readonly ?OtpSettings $settings = null,
		private readonly ?PushOtpSecretMatcher $matcher = null,
		?callable $personalOtpFactory = null,
	)
	{
		$this->personalOtpFactory = $personalOtpFactory
			?? static fn(User $user): PersonalOtp => new PersonalOtp($user);
	}

	public function check(User $user, string $fingerprint): Result
	{
		try
		{
			return $this->doCheck($user, $fingerprint);
		}
		catch (\Throwable)
		{
			$result = new Result();
			$result->addError(new Error('Trusted device check is unavailable', self::ERROR_UNAVAILABLE));

			return $result;
		}
	}

	private function doCheck(User $user, string $fingerprint): Result
	{
		$settings = $this->settings ?? new OtpSettings();
		if (!$settings->isAvailable())
		{
			throw new \RuntimeException('Module security is not installed');
		}

		if (!$settings->isEnabled())
		{
			return $this->negative();
		}

		$personalOtp = ($this->personalOtpFactory)($user);

		if (!$personalOtp->isActivated() && $personalOtp->getDeactivateUntil() === null)
		{
			return $this->negative();
		}

		if (!$personalOtp->isPushType())
		{
			return $this->negative();
		}

		$matcher = $this->matcher ?? new PushOtpSecretMatcher();

		return (new Result())->setData([
			'isTrusted' => $matcher->matches((int)$user->getId(), $fingerprint),
		]);
	}

	private function negative(): Result
	{
		return (new Result())->setData(['isTrusted' => false]);
	}
}
