<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Provider\UserProviderTrait;
use Bitrix\Tasks\Util\User;

class AccessChecker
{
	use UserProviderTrait;

	public function __construct(int $userId)
	{
		$this->executorId = $userId;
	}

	public function hasAccess(): bool
	{
		return $this->isAdmin()
			|| $this->hasAccessToDepartmentTemplates()
			|| $this->hasAccessToNonDepartmentTemplates()
			|| $this->hasAccessByIndividualRights();
	}

	public function isAdmin(): bool
	{
		return User::isSuper($this->executorId);
	}

	public function hasAccessToDepartmentTemplates(): bool
	{
		$permissions = $this->getPermissions();
		$departmentMembers = $this->getDepartmentMembers();

		return !empty($departmentMembers)
			&& in_array(PermissionDictionary::TEMPLATE_DEPARTMENT_VIEW, $permissions, true);
	}

	public function hasAccessToNonDepartmentTemplates(): bool
	{
		$permissions = $this->getPermissions();
		return in_array(PermissionDictionary::TEMPLATE_NON_DEPARTMENT_VIEW, $permissions, true);
	}

	public function hasAccessByIndividualRights(): bool
	{
		return !empty($this->getAccessCodes());
	}

	public function getDepartmentMemberIds(): array
	{
		return $this->getDepartmentMembers();
	}

	public function getAccessCodes(): array
	{
		return $this->getUserModel()->getAccessCodes();
	}
}
