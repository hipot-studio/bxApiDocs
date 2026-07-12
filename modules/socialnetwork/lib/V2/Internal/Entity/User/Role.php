<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\User;

enum Role: string
{
	case Owner = 'A';
	case Moderator = 'E';
	case Member = 'K';
	case Ban = 'T';
	case Request = 'Z';
}
