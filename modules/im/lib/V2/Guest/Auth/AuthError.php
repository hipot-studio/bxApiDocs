<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Error;

class AuthError extends Error
{
	public const CHAT_TYPE_NOT_ALLOWED = 'CHAT_TYPE_NOT_ALLOWED';
	public const GUEST_FEATURE_DISABLED = 'GUEST_FEATURE_DISABLED';
	public const GUEST_REGISTRATION_ERROR = 'GUEST_REGISTRATION_ERROR';
	public const AUTHORIZE_ERROR = 'AUTHORIZE_ERROR';
	public const GUEST_NOT_FOUND = 'GUEST_NOT_FOUND';
	public const DIFFERENT_GUEST = 'DIFFERENT_GUEST';
	/** The actor is no guest and is expected to act under their own portal account instead. */
	public const NOT_GUEST = 'NOT_GUEST';
	/** A visitor of a contour of its own: no guest of ours and no account here {@see \Bitrix\Im\V2\Guest\GuestService} */
	public const FOREIGN_CONTOUR = 'FOREIGN_CONTOUR';
	public const GUEST_METHOD_NOT_ALLOWED = 'GUEST_METHOD_NOT_ALLOWED';
	public const GUEST_SESSION_TERMINATED = 'GUEST_SESSION_TERMINATED';
	public const GUEST_SESSION_SUSPENDED = 'GUEST_SESSION_SUSPENDED';
	public const JOIN_CHAT_ERROR = 'JOIN_CHAT_ERROR';
	public const GUEST_INVALID_NAME = 'GUEST_INVALID_NAME';
	public const GUEST_UPDATE_NAME_ERROR = 'GUEST_UPDATE_NAME_ERROR';
}
