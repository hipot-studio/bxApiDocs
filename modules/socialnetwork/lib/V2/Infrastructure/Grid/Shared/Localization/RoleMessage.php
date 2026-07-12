<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Localization;

use Bitrix\Main\Localization\Loc;
use Bitrix\Socialnetwork\V2\Internal\Entity\User\Role;

class RoleMessage
{
	public const BAN = 'ROLE_BAN';
	public const REQUEST_G = 'ROLE_REQUEST_G';
	public const REQUEST_U = 'ROLE_REQUEST_U';
	public const SCRUM_MASTER = 'ROLE_SCRUM_MASTER';

	public static function get(string $code): string
	{
		return Loc::getMessage('SONET_V2_GRID_SHARED_' . $code) ?? '';
	}

	public static function getProjectRole(Role $role, bool $autoMember = false): string
	{
		return self::getRole($role, '_PROJECT', $autoMember);
	}

	public static function getScrumRole(Role $role, bool $autoMember = false): string
	{
		return self::getRole($role, '_SCRUM', $autoMember);
	}

	private static function getRole(Role $role, string $suffix, bool $autoMember): string
	{
		return Loc::getMessage(
			'SONET_V2_GRID_SHARED_ROLE_'
			. $role->value
			. $suffix
			. ($autoMember ? '_AUTO' : '')
		) ?? '';
	}
}
