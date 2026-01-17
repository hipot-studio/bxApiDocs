<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Internals\Notification\UseCase;

enum TaskUpdatedV2Action: string
{
	case RemoveUser = 'removeUser';
	case AddAsAuditor = 'addAsAuditor';
	case AddAsAccomplice = 'addAsAccomplice';
}
