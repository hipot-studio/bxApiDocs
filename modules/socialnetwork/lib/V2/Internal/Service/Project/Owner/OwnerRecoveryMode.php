<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner;

enum OwnerRecoveryMode: string
{
	case Interactive = 'interactive';
	case Silent = 'silent';
}
