<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Service\Project\Owner;

enum OwnerRecoveryStatus: string
{
	case Unchanged = 'unchanged';
	case NoCandidate = 'no_candidate';
	case OwnerChanged = 'owner_changed';
}
