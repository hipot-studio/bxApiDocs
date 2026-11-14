<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum;

enum ObjectRightsChangeMode: string
{
	case Grant = 'grant';
	case Revoke = 'revoke';
}
