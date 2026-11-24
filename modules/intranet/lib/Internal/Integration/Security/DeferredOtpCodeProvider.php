<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Security;

use Bitrix\Security\Controller\PushOtp;

/**
 * Opens the existing push OTP generator of the security module for a server-side call.
 *
 * The whole guard chain stays in security: the user is taken from the deferred login context only,
 * the type must be push and the code is produced only for an activated OTP of an active user.
 *
 * The class declares no *Action method, so it adds no ajax action, and it lives outside the
 * controller namespaces allowed for intranet, so the router cannot reach it.
 */
final class DeferredOtpCodeProvider extends PushOtp
{
	public const ERROR_OTP_PARAMS = 'OTP_PARAMS';

	/**
	 * The code is meant to be consumed by the native login check right away: it must not be
	 * stored, logged or returned to a client.
	 */
	public function generateCodeForDeferredLogin(): ?string
	{
		$otp = $this->getOtp();

		return $otp === null ? null : $this->getOtpCode($otp);
	}
}
