<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity\Project\Member;

enum MemberEntityType: string
{
	case User = 'user';
	case Department = 'department';
}
