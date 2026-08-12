<?php


declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Socialnetwork;

use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\UserToGroupTable;

final class UserToGroupRoleService
{
	private bool $isAvailable;

	public function __construct()
	{
		$this->isAvailable =
			Loader::includeModule('socialnetwork')
			&& class_exists(UserToGroupTable::class)
		;
	}

	public function isAvailable(): bool
	{
		return $this->isAvailable;
	}

	public function isRequest(?string $role): bool
	{
		return
			$this->isAvailable
			&& $role === UserToGroupTable::ROLE_REQUEST
		;
	}

	public function isMember(?string $role): bool
	{
		return
			$this->isAvailable
			&& in_array($role, UserToGroupTable::getRolesMember(), true)
		;
	}

	public function isGroupInitiatedRequest(?string $role, ?string $initiatedByType): bool
	{
		return
			$this->isAvailable
			&& $role === UserToGroupTable::ROLE_REQUEST
			&& $initiatedByType === UserToGroupTable::INITIATED_BY_GROUP
		;
	}
}
