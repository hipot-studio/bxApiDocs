<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository;

enum MemberFilterType: string
{
	case All = 'all';
	case Heads = 'heads';
	case Members = 'members';
}
