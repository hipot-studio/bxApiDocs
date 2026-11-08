<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Service\Otp;

use Bitrix\Intranet\Internal\Integration\Main\OtpSigner;
use Bitrix\Security\Mfa\Otp;

final class DeferredSkipDeviceConnectAccess
{
	public function getDeferredUserId(?string $signedUserId): ?int
	{
		if ($signedUserId === null)
		{
			return null;
		}

		$params = Otp::getDeferredParams();
		if (!is_array($params) || ($params[Otp::REJECTED_KEY] ?? null) !== Otp::REJECT_BY_MANDATORY)
		{
			return null;
		}

		$userId = (int)($params['USER_ID'] ?? 0);
		$signedId = (new OtpSigner())->extractUserId($signedUserId);

		return $userId > 0 && $signedId === $userId ? $userId : null;
	}
}
