<?php

namespace Bitrix\Crm\Security\Controller\QueryBuilder\RestrictionByAttributes;

use Bitrix\Crm\Integration\HumanResources\DepartmentQueries;
use Bitrix\Crm\Traits\Singleton;

class DepartmentProvider
{
	use Singleton;

	private DepartmentQueries $departmentQueries;

	public function __construct()
	{
		$this->departmentQueries = DepartmentQueries::getInstance();
	}

	public function getHrDepartmentUsers(array $nodeIds): array
	{
		static $users = [];

		if (empty($nodeIds))
		{
			return [];
		}

		$cacheKey = md5(implode(',', $nodeIds));

		if (!isset($users[$cacheKey]))
		{
			$userIds = $this->departmentQueries->getUserIdsByHrNodeIds($nodeIds);
			$users[$cacheKey] = array_unique($userIds);
		}

		return $users[$cacheKey];
	}
}
