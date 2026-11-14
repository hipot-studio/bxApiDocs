<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\Audit;

enum AuditEventGroup: string
{
	case Object = 'object';
	case Trashcan = 'trashcan';
	case Rights = 'rights';
	case Link = 'link';
}
