<?php

namespace Bitrix\Crm\Integration\HumanResources;

use Bitrix\Crm\Traits\Singleton;
use Bitrix\HumanResources;
use Bitrix\HumanResources\Item\NodeMember;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;

Loader::requireModule('humanresources');

class DepartmentQueries
{
	use Singleton;

	public function getUserIdsByHrDepartmentId(int $departmentId, bool $excludeHead = false, bool $withSubDepartments = true): array
	{
		return $this->getUserIdsByHrDepartmentsIds([$departmentId], $excludeHead, $withSubDepartments);
	}

	public function getUserIdsByHrDepartmentsIds(array $departmentIds, bool $excludeHead = false, bool $withSubDepartments = true): array
	{
		$nodes = HumanResources\Public\Service\Container::getNodeService()->findAllByIds($departmentIds);

		return $this->getUserIdsByDepartmentNodeCollection($nodes, $excludeHead, $withSubDepartments);
	}

	public function getUserIdsByHrNodeIds(array $nodeIds, bool $excludeHead = false, bool $withSubDepartments = false): array
	{
		$nodes = HumanResources\Public\Service\Container::getNodeService()->findAllByIds(
			nodeIds: $nodeIds,
			nodeTypes: [NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
		);

		return $this->getUserIdsByDepartmentNodeCollection($nodes, $excludeHead, $withSubDepartments);
	}

	/**
	 * Get users from the same departments as $userId
	 * @param int $userId
	 * @param bool $withSubDepartments
	 * @return array
	 */
	public function getUserColleagues(int $userId, bool $withSubDepartments): array
	{
		$hrDepartmentIds = $this->getUserHrDepartmentsIds($userId);
		$allDepartmentsIds = $hrDepartmentIds;
		if ($withSubDepartments)
		{
			$allDepartmentsIds = array_merge(
				$allDepartmentsIds,
				$this->getHrChildNodesIds($hrDepartmentIds)
			);
		}

		return $this->getUserIdsByHrDepartmentsIds($allDepartmentsIds);
	}

	/**
	 * @deprecated Intranet departments are deprecated
	 */
	public function getUserIdsByIntranetDepartmentsAccessCodes(array $departmentAccessCodes, bool $excludeHead = false): array
	{
		$departmentAccessCodes = array_map(
			static fn($code) => is_numeric($code) ? 'D' . $code : $code,
			$departmentAccessCodes
		);
		$nodes = HumanResources\Public\Service\Container::getNodeService()->findAllByAccessCodes($departmentAccessCodes);

		return $this->getUserIdsByDepartmentNodeCollection($nodes, $excludeHead, false);
	}

	/**
	 * Returns all access codes cast to int owned by department as children.
	 * @deprecated Intranet departments are deprecated
	 *
	 */
	public function getIntranetSubDepartmentsAccessCodesIds(int $departmentId): array
	{
		$department = HumanResources\Public\Service\Container::getNodeService()->getByAccessCode('D' . $departmentId);

		if (empty($department))
		{
			return [];
		}

		$children = HumanResources\Public\Service\Container::getNodeService()->findChildrenByNodeIds(
			[$department->id],
			null,
			[NodeEntityType::DEPARTMENT],
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

	private function getUserHrDepartmentsIds(int $userId): array
	{
		$result = [];
		$nodes = HumanResources\Public\Service\Container::getNodeService()->findAllByMemberEntityId($userId);
		foreach ($nodes as $node)
		{
			$result[] = $node->id;
		}

		return $result;
	}
	/**
	 * @deprecated Intranet departments are deprecated
	 */
	public function getDepartments(array $ids): array
	{
		return HumanResources\Public\Service\Container::getNodeService()->findAllByIds($ids)->getValues();
	}

	/**
	 * @deprecated Intranet departments are deprecated
	 * @internal Used in data converter agent only
	 */
	public function getHrDepartmentByIntranetAccessCode(string $accessCode): ?HumanResources\Item\Node
	{
		return HumanResources\Public\Service\Container::getNodeService()->getByAccessCode($accessCode);
	}

	public function getHrChildNodesIds(array $parentIds): array
	{
		return $this->getChildNodesIdsByType($parentIds, [NodeEntityType::DEPARTMENT]);
	}

	public function getHrChildTeamNodesIds(array $parentIds): array
	{
		return $this->getChildNodesIdsByType($parentIds, [NodeEntityType::TEAM]);
	}

	private function getChildNodesIdsByType(array $parentIds, array $nodeTypes): array
	{
		$nodeService = HumanResources\Public\Service\Container::getNodeService();
		$parents = $nodeService->findAllByIds(
			nodeIds: $parentIds,
			nodeTypes: $nodeTypes,
		);
		if (!$parents->count())
		{
			return [];
		}

		$children = $nodeService->findChildrenByNodeIds(
			$parents->getIds(),
			null,
			$nodeTypes,
			HumanResources\Enum\DepthLevel::FULL,
		);

		return $children->getIds();
	}

	private function getUserIdsByDepartmentNodeCollection(
		\Bitrix\HumanResources\Item\Collection\NodeCollection $nodes,
		bool $excludeHead,
		bool $withSubDepartments,
	): array
	{
		$headRole = Container::getRoleRepository()->findByXmlId(NodeMember::DEFAULT_ROLE_XML_ID['HEAD'])?->id;

		$userIds = [];
		$headIds = [];
		foreach ($nodes as $node)
		{
			$allEmp = HumanResources\Service\Container::instance()::getNodeMemberService()->getAllEmployees($node->id, $withSubDepartments, false);
			foreach ($allEmp->getIterator() as $emp)
			{
				if ($excludeHead && in_array($headRole, $emp->roles, true))
				{
					$headIds[] = $node->entityId;

					continue;
				}

				$userIds[] = $emp->entityId;
			}

			$userIds = array_diff($userIds, $headIds);
		}

		return array_values(array_unique($userIds));
	}
}
