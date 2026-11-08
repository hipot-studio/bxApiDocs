<?php

namespace Bitrix\StaffTrack\Access;

use Bitrix\StaffTrack\Access\Model\UserModel;
use Bitrix\StaffTrack\Integration\HumanResources\DepartmentAccess;
use Bitrix\StaffTrack\Trait\Singleton;

/**
 * The circle of users who manage offices: the single source of the verdict for the office write rules
 * and for the read-side flags, so the two cannot drift apart. A portal administrator belongs to it
 * without the structure module being asked; otherwise the structure adapter decides.
 *
 * Answers are remembered per user until the end of the process, as the adapter does, and never reset:
 * ask within one HTTP request only - long-lived processes (agents, queues) must not.
 */
class OfficeCustody
{
	use Singleton;

	private array $isMemberCache = [];

	public function isMember(int $userId): bool
	{
		return $this->isMemberCache[$userId] ??= $this->resolveIsMember($userId);
	}

	private function resolveIsMember(int $userId): bool
	{
		if ($this->isAdmin($userId))
		{
			return true;
		}

		$departmentAccess = $this->getDepartmentAccess();

		return $departmentAccess->canEditAllDepartments($userId)
			|| $departmentAccess->isHeadOrDeputyOfDepartment($userId)
		;
	}

	protected function isAdmin(int $userId): bool
	{
		return UserModel::createFromId($userId)->isAdmin();
	}

	protected function getDepartmentAccess(): DepartmentAccess
	{
		return DepartmentAccess::getInstance();
	}
}
