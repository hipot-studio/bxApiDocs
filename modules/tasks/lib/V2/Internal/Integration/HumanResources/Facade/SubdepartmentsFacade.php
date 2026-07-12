<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\HumanResources\Facade;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;

class SubdepartmentsFacade
{
	public function __construct(
		private readonly DepartmentsFacade $departmentsFacade,
	)
	{
	}

	/**
	 * @param int[] $oldDepartmentsIds
	 * @return int[]
	 */
	public function getByOldDepartmentsIds(array $oldDepartmentsIds): array
	{
		if (empty($oldDepartmentsIds))
		{
			return [];
		}

		$departmentIds = $this->departmentsFacade->getByOldIds($oldDepartmentsIds);

		return $this->getByDepartmentsIds($departmentIds);
	}

	/**
	 * @param int[] $departmentsIds
	 * @return int[]
	 */
	private function getByDepartmentsIds(array $departmentsIds): array
	{
		$result = [];

		if (empty($departmentsIds))
		{
			return $result;
		}

		$departmentsIds = array_values(array_unique($departmentsIds));

		// DepthLevel::FULL includes the parent nodes along with all nested children
		$subdepartments = Container::getNodeService()->findChildrenByNodeIds(
			nodeIds: $departmentsIds,
			nodeTypes: [NodeEntityType::DEPARTMENT],
			depthLevel: DepthLevel::FULL,
		);

		/**	@var Node $subdepartment */
		foreach ($subdepartments as $subdepartment)
		{
			$result[$subdepartment->id] = true;
		}

		return array_keys(
			$result,
		);
	}
}
