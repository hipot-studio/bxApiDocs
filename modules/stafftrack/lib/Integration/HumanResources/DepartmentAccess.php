<?php

namespace Bitrix\StaffTrack\Integration\HumanResources;

use Bitrix\HumanResources\Access\Permission\PermissionVariablesDictionary;
use Bitrix\HumanResources\Service\Access\Structure\StructureAccessService;
use Bitrix\HumanResources\Type\StructureAction;
use Bitrix\Main\Loader;
use Bitrix\StaffTrack\Internal\Integration\HumanResources\DepartmentProvider;
use Bitrix\StaffTrack\Trait\Singleton;

/**
 * The only seam through which the access layer asks the structure module. It answers two questions,
 * both about the structure alone: whether the user may edit every department, and whether the user
 * heads or deputises at least one department. The second answer is taken from the internal facade
 * rather than computed here. Each answer is remembered per user separately.
 */
class DepartmentAccess
{
	use Singleton;

	private array $canEditAllDepartmentsCache = [];

	private array $isHeadOrDeputyOfDepartmentCache = [];

	public function canEditAllDepartments(int $userId): bool
	{
		return $this->canEditAllDepartmentsCache[$userId] ??= $this->resolveCanEditAllDepartments($userId);
	}

	public function isHeadOrDeputyOfDepartment(int $userId): bool
	{
		return $this->isHeadOrDeputyOfDepartmentCache[$userId] ??= $this->resolveIsHeadOrDeputyOfDepartment($userId);
	}

	private function resolveCanEditAllDepartments(int $userId): bool
	{
		if (!$this->isStructureAvailable())
		{
			return false;
		}

		$permissionLevel = $this->getStructureAccessService($userId)
			->setAction(StructureAction::UpdateAction)
			->getPermissionValue()
			->getFirst()
			?->value
		;

		return $permissionLevel === PermissionVariablesDictionary::VARIABLE_ALL;
	}

	private function resolveIsHeadOrDeputyOfDepartment(int $userId): bool
	{
		if (!$this->isStructureAvailable())
		{
			return false;
		}

		return $this->isHeadOrDeputyOfDepartmentInStructure($userId);
	}

	protected function isStructureAvailable(): bool
	{
		return Loader::includeModule('humanresources');
	}

	protected function getStructureAccessService(int $userId): StructureAccessService
	{
		return new StructureAccessService($userId);
	}

	protected function isHeadOrDeputyOfDepartmentInStructure(int $userId): bool
	{
		return DepartmentProvider::isHeadOrDeputyOfDepartment($userId);
	}
}
