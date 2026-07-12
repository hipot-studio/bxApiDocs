<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\User;

enum Type: string
{
	case Employee = 'employee';
	case Extranet = 'extranet';
	case Collaber = 'collaber';
}
