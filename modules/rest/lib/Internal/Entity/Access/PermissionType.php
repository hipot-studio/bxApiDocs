<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Access;

enum PermissionType: string
{
	case CreateOwn = 'create_own';
	case ManageOwn = 'manage_own';
	case Create = 'create';
	case Manage = 'manage';
}
