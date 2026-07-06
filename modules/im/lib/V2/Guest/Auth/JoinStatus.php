<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

enum JoinStatus: string
{
	case NEW_GUEST = 'new_guest';
	case RETURNING_GUEST = 'returning_guest';
	case PORTAL_USER = 'portal_user';
}
