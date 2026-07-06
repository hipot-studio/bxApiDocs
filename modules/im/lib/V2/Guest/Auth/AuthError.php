<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Error;

class AuthError extends Error
{
	public const CHAT_TYPE_NOT_ALLOWED = 'CHAT_TYPE_NOT_ALLOWED';
	public const FEATURE_DISABLED = 'FEATURE_DISABLED';
	public const REGISTRATION_ERROR = 'REGISTRATION_ERROR';
	public const AUTHORIZE_ERROR = 'AUTHORIZE_ERROR';
	public const USER_NOT_FOUND = 'USER_NOT_FOUND';
	public const DIFFERENT_GUEST = 'DIFFERENT_GUEST';
	public const NOT_GUEST = 'NOT_GUEST';
	public const METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
	public const USES_LIMIT_REACHED = 'USES_LIMIT_REACHED';
	public const JOIN_CHAT_ERROR = 'JOIN_CHAT_ERROR';
	public const INVALID_NAME = 'INVALID_NAME';
	public const UPDATE_NAME_ERROR = 'UPDATE_NAME_ERROR';
}
