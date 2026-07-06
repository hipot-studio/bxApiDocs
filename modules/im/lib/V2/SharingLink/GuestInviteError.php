<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\V2\Error;

class GuestInviteError extends Error
{
	public const INVALID_EMAIL = "GUEST_INVITE_INVALID_EMAIL";
	public const INVALID_PHONE = "GUEST_INVITE_INVALID_PHONE";
	public const SMS_NOT_CONNECTED = "GUEST_INVITE_SMS_NOT_CONNECTED";
	public const SMS_SEND_FAILED = "GUEST_INVITE_SMS_SEND_FAILED";
	public const EMAIL_SEND_FAILED = "GUEST_INVITE_EMAIL_SEND_FAILED";
	public const INVITE_COOLDOWN = "GUEST_INVITE_COOLDOWN";
	public const AUTHOR_RATE_LIMIT = "GUEST_INVITE_AUTHOR_RATE_LIMIT";
}
