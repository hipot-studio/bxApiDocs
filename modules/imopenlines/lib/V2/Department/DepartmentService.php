<?php

namespace Bitrix\ImOpenLines\V2\Department;

use Bitrix\Im\V2\Common\Normalizer;
use Bitrix\ImOpenLines\Integrations\HumanResources\StructureService;
use Bitrix\Main\Result;

class DepartmentService
{
	public function __construct(
		private readonly StructureService $structureService,
	)
	{
	}

	public function getList(): Result
	{
		$result = new Result();

		$departments = [];
		foreach ($this->structureService->getStructure() as $department)
		{
			$departments[] = [
				'id' => (int)$department['id'],
				'name' => (string)$department['name'],
			];
		}

		$result->setData(['departments' => $departments]);

		return $result;
	}

	/**
	 * Resolves explicit users and department members into a unique positive id list.
	 * Department heads are included, recursion covers nested departments.
	 *
	 * @param int[] $userIds
	 * @param int[] $departmentIds
	 * @return int[]
	 */
	public function resolveUserIds(array $userIds, array $departmentIds): array
	{
		$departmentUserIds = [];
		foreach ($departmentIds as $departmentId)
		{
			$departmentUserIds[] = $this->structureService->getDepartmentUserIds((int)$departmentId, excludeHead: false);
		}

		return Normalizer::toUniquePositiveIntegers(array_merge($userIds, ...$departmentUserIds));
	}
}
