<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Main\Loader;
use Bitrix\Main\Security\Mfa\TotpAlgorithm;
use Bitrix\Security\Mfa\Otp;

final class SecondFactorCodeProvider
{
	public function getCode(int $userId): ?string
	{
		if ($userId <= 0)
		{
			return null;
		}

		return $this->getCodeFor(Loader::includeModule('security') ? Otp::getByUser($userId) : null);
	}

	public function getCodeFor(?Otp $otp): ?string
	{
		$algorithm = $this->resolveAlgorithm($otp);

		return $algorithm === null ? null : $algorithm->generateOTP($algorithm->timecode(time()));
	}

	/**
	 * Answers the same as getCodeFor() !== null without spending the HMAC: generateOTP() always returns
	 * a code once the factor resolves to a time-based algorithm.
	 */
	public function hasCodeFor(?Otp $otp): bool
	{
		return $this->resolveAlgorithm($otp) !== null;
	}

	private function resolveAlgorithm(?Otp $otp): ?TotpAlgorithm
	{
		if ($otp === null || !$otp->isActivated() || !$otp->isUserActive())
		{
			return null;
		}

		$algorithm = $otp->getAlgorithm();

		return $algorithm instanceof TotpAlgorithm ? $algorithm : null;
	}
}
