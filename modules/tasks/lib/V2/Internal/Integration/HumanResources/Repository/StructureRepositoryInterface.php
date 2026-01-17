<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Repository;

use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\Department;
use Bitrix\Tasks\V2\Internal\Integration\HumanResources\Entity\DepartmentCollection;

interface StructureRepositoryInterface
{
	public function getMainDepartment(): ?Department;
	public function getDepartmentsByAccessCodes(array $accessCodes): DepartmentCollection;
}
