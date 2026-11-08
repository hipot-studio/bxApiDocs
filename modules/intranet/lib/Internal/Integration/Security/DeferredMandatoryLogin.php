<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Uri;
use Bitrix\Security\Mfa\Otp;

class DeferredMandatoryLogin
{
	public function tryResumeLogin(string $loginUrl, string $successfulUrl): ?string
	{
		global $USER;

		if (!Loader::includeModule('security'))
		{
			return $loginUrl;
		}

		$params = Otp::getDeferredParams();
		if (!is_array($params) || (int)($params['USER_ID'] ?? 0) <= 0)
		{
			return $loginUrl;
		}

		$userId = (int)$params['USER_ID'];
		$reason = $params[Otp::REJECTED_KEY] ?? null;
		if ($reason === Otp::REJECT_BY_CODE)
		{
			return (new Uri($loginUrl))->deleteParams(['login_form'])->getUri();
		}
		if ($reason !== Otp::REJECT_BY_MANDATORY)
		{
			return $loginUrl;
		}

		$otp = Otp::getByUser($userId);
		if (!$otp->isActivated() && Otp::isMandatoryUsing() && !$otp->canSkipMandatory())
		{
			return null;
		}

		if ($USER->LoginByOtp('') === true)
		{
			return $USER->IsAuthorized() && (int)$USER->GetID() === $userId
				? $successfulUrl
				: $loginUrl;
		}

		if ((Otp::getDeferredParams()[Otp::REJECTED_KEY] ?? null) === Otp::REJECT_BY_CODE)
		{
			return (new Uri($loginUrl))->deleteParams(['login_form'])->getUri();
		}

		return null;
	}
}
