<?php

namespace Bitrix\Crm\Integration\HumanResources;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\HumanResources;
use Bitrix\Main\Loader;

Loader::requireModule('humanresources');

class DepartmentQueries
{
	use Singleton;

	private HumanResources\Service\Container $hrServiceLocator;

	public function __construct()
	{
		$this->hrServiceLocator = HumanResources\Service\Container::instance();
	}

	public function queryUserIdsByDepartments(array $departmentAccessCodes): array
	{
		$departmentAccessCodes = array_map(function ($code) {
			if (is_numeric($code))
			{
				$code = 'D' . $code;
			}

			return $code;
		}, $departmentAccessCodes);
		$nodes = $this->hrServiceLocator::getNodeRepository()->findAllByAccessCodes($departmentAccessCodes);
		$userIds = [];
		foreach ($nodes as $node)
		{
			$allEmp = $this->hrServiceLocator::getNodeMemberService()->getAllEmployees($node->id, false, false);

			foreach ($allEmp->getIterator() as $emp)
			{
				$userIds[] = $emp->entityId;
			}
		}

		return $userIds;
	}

	/**
	 * Returns all access codes cast to int owned by department as children.
	 * @param int $departmentId
	 * @return int[]
	 */
	public function getSubDepartmentsAccessCodesIds(int $departmentId): array
	{
		$department = $this->hrServiceLocator::getNodeRepository()->getByAccessCode('D' . $departmentId);

		if (empty($department))
		{
			return [];
		}

		$children = $this->hrServiceLocator::getNodeRepository()->getChildOf(
			 $department,
			 HumanResources\Enum\DepthLevel::FULL
		 );

		$result = [];

		/** @var HumanResources\Item\Node $dep */
		foreach ($children->getIterator() as $dep)
		{
			$ac = $dep->accessCode;
			if (!str_starts_with($ac, 'D'))
			{
				continue;
			}

			$depId = (int)substr($ac, 1);

			if ($depId <= 0 || $depId == $departmentId)
			{
				continue;
			}

			$result[] = $depId;
		}

		return $result;
	}
}