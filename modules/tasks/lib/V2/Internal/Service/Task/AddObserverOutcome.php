<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task;

enum AddObserverOutcome: string
{
	case Success = 'success';
	case AlreadyObserver = 'already_observer';
	case UserInactive = 'user_inactive';
	case NotAllowedByPolicy = 'not_allowed_by_policy';
	case NoPermission = 'no_permission';
	case NoRequest = 'no_request';
}
