<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Error;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

final class SmsReInvitationTimeoutError extends Error
{
	public const CODE = 'ERROR_TIMEOUT_NOT_EXCEEDED';

	public function __construct(mixed $customData = null)
	{
		parent::__construct(
			(string)Loc::getMessage('INTRANET_SMS_REINVITATION_TIMEOUT_ERROR'),
			self::CODE,
			$customData,
		);
	}
}
