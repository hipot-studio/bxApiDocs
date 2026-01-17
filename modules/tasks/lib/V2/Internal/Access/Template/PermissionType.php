<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Access\Template;

enum PermissionType: string
{
	case ReadOnly = 'read_only';
	case Full = 'full';
}
