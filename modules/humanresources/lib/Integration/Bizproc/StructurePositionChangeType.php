<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

enum StructurePositionChangeType: string
{
	case Added = 'added';
	case Removed = 'removed';
	case Moved = 'moved';
	case RoleAssigned = 'role_assigned';
	case RoleRevoked = 'role_revoked';
}
