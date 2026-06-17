<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List;

use Bitrix\Tasks\Access\Permission\PermissionDictionary;
use Bitrix\Tasks\Provider\UserProviderTrait;

class AccessChecker
{
	use UserProviderTrait;

	public function __construct(int $userId)
	{
		$this->executorId = $userId;
	}

	public function hasAccess(): bool
	{
		return $this->hasAccessToDepartmentTemplates()
			|| $this->hasAccessToNonDepartmentTemplates()
			|| $this->hasAccessByIndividualRights();
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
		$accessCodes = $this->getUserModel()->getAccessCodes();
		return !empty($accessCodes);
	}
}