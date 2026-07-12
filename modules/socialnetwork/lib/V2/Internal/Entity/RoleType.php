<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Entity;

enum RoleType: string
{
	/** @see SONET_ROLES_NONE */
	case None = '0';

	/** @see SONET_ROLES_OWNER */
	case Owner = 'A';

	/** @see SONET_ROLES_MODERATOR */
	case Moderator = 'E';

	/** @see SONET_ROLES_USER */
	case User = 'K';

	/** @see SONET_ROLES_BAN */
	case Ban = 'T';

	/** @see SONET_ROLES_REQUEST */
	case Request = 'Z';

	/** @see SONET_ROLES_EMPLOYEE */
	case Employee = 'J';

	/** @see SONET_ROLES_ALL */
	case All = 'N';

	/** @see SONET_ROLES_AUTHORIZED */
	case Authorized = 'L';
}
