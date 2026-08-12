<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

enum InviteMethod: string
{
	case Email = 'email';
	case Phone = 'phone';
	case Link = 'link';
}
