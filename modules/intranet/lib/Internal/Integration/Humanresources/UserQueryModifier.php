<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Internals\Service\Container as HumanResourcesContainer;
use Bitrix\HumanResources\Model\NodeTable;
use Bitrix\HumanResources\Model\NodeMemberRoleTable;
use Bitrix\HumanResources\Model\NodeMemberTable;
use Bitrix\HumanResources\Model\RoleTable;
use Bitrix\HumanResources\Public\Service\Container as HumanResourcesPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserTable;

final class UserQueryModifier
{
	private bool $isAvailable;
	private array $userIdSubQueryCache = [];

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');
	}

	public function injectStructureSort(Query $query, int $userId): Query
	{
		if (
			!$this->isAvailable
			|| $userId <= 0
		)
		{
			return $query;
		}

		$this->injectUserNodeSortJoin($query);
		$this->injectDepartmentPrioritySort($query, $userId);
		$this->injectRolePrioritySort($query);
		$this->addUserGroup($query);

		return $query;
	}

	public function createEmployeeUserIdQuery(?array $nodeIds = null): ?Query
	{
		if (!$this->isAvailable)
		{
			return null;
		}

		$query = UserTable::query()
			->setSelect(['ID'])
			->setGroup(['ID'])
		;

		return HumanResourcesContainer::getNodeMemberRepository()->injectUserNodeSubquery(
			$query,
			active: null,
			nodeIds: !empty($nodeIds) ? $nodeIds : null,
		);
	}

	public function createEmployeeUserIdSubQuery(?array $nodeIds = null): ?string
	{
		$cacheKey = $this->getSubQueryCacheKey($nodeIds);

		if (array_key_exists($cacheKey, $this->userIdSubQueryCache))
		{
			return $this->userIdSubQueryCache[$cacheKey];
		}

		$query = $this->createEmployeeUserIdQuery($nodeIds);

		if ($query === null)
		{
			return null;
		}

		$this->userIdSubQueryCache[$cacheKey] = $query->getQuery();

		return $this->userIdSubQueryCache[$cacheKey];
	}

	public function createDepartmentUserIdSubQuery(
		string|int $departmentFilterValue,
		bool $withSubDepartments = true,
	): ?string
	{
		$preparedDepartmentFilter = $this->prepareDepartmentFilterValue(
			$departmentFilterValue,
			$withSubDepartments,
		);
		if ($preparedDepartmentFilter === null)
		{
			return null;
		}

		[$departmentId, $withSubDepartments] = $preparedDepartmentFilter;
		$cacheKey = 'department:' . $departmentId . ':' . ($withSubDepartments ? 'Y' : 'N');
		if (array_key_exists($cacheKey, $this->userIdSubQueryCache))
		{
			return $this->userIdSubQueryCache[$cacheKey];
		}

		$nodeIds = $this->resolveDepartmentNodeIds($departmentId, $withSubDepartments);
		if (empty($nodeIds))
		{
			return null;
		}

		$this->userIdSubQueryCache[$cacheKey] = $this->createEmployeeUserIdSubQuery($nodeIds);

		return $this->userIdSubQueryCache[$cacheKey];
	}

	public function createDepartmentUserIdSubQueryFromFilter(array $filterValue): ?string
	{
		$departmentFilterValue = null;
		$withSubDepartments = true;

		if (
			!empty($filterValue['DEPARTMENT'])
			&& is_scalar($filterValue['DEPARTMENT'])
		)
		{
			$departmentFilterValue = (string)$filterValue['DEPARTMENT'];
		}
		elseif (
			!empty($filterValue['DEPARTMENT_FLAT'])
			&& is_scalar($filterValue['DEPARTMENT_FLAT'])
		)
		{
			$departmentFilterValue = (string)$filterValue['DEPARTMENT_FLAT'];
			$withSubDepartments = false;
		}

		if ($departmentFilterValue === null)
		{
			return null;
		}

		return $this->createDepartmentUserIdSubQuery(
			$departmentFilterValue,
			$withSubDepartments,
		);
	}

	private function resolveDepartmentNodeIds(int $departmentId, bool $withSubDepartments): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		$departmentIdMap = [
			$departmentId => $departmentId,
		];

		if ($withSubDepartments)
		{
			foreach (
				HumanResourcesPublicContainer::getNodeService()->findChildrenByNodeIds(
					nodeIds: [$departmentId],
					nodeTypes: [NodeEntityType::DEPARTMENT],
					depthLevel: DepthLevel::FULL,
				)->getIds()
				as $subDepartmentId
			)
			{
				$departmentIdMap[$subDepartmentId] = $subDepartmentId;
			}
		}

		return array_values($departmentIdMap);
	}

	private function prepareDepartmentFilterValue(
		string|int $departmentFilterValue,
		bool $withSubDepartments = true,
	): ?array
	{
		$departmentFilterValue = trim((string)$departmentFilterValue);

		if (preg_match('/^(\d+):F$/', $departmentFilterValue, $matches))
		{
			return [(int)$matches[1], false];
		}

		if (preg_match('/^(\d+)$/', $departmentFilterValue, $matches))
		{
			return [(int)$matches[1], $withSubDepartments];
		}

		return null;
	}

	private function injectUserNodeSortJoin(Query $query): void
	{
		$departmentNodeIdQuery = NodeTable::query()
			->setSelect(['ID'])
			->where('TYPE', NodeEntityType::DEPARTMENT->value)
		;

		$query->registerRuntimeField(
			new Reference(
				'USER_NODE_MEMBER',
				\Bitrix\HumanResources\Model\NodeMember::class,
				Join::on('this.ID', 'ref.ENTITY_ID')
					->where('ref.ENTITY_TYPE', MemberEntityType::USER->value)
					->where('ref.ACTIVE', 'Y')
					->whereIn('ref.NODE_ID', $departmentNodeIdQuery),
				['join_type' => Join::TYPE_LEFT],
			),
		);
	}

	private function injectDepartmentPrioritySort(Query $query, int $userId): void
	{
		$nodeIds = $this->getUserDepartmentNodeIds($userId);
		if (empty($nodeIds))
		{
			return;
		}

		$query->registerRuntimeField(
			new ExpressionField(
				'USER_NODE_DEPT_PRIORITY',
				'MIN(CASE WHEN %s IN (' . implode(',', $nodeIds) . ') THEN 0 ELSE 1 END)',
				['USER_NODE_MEMBER.NODE_ID'],
			),
		);
		$query->addOrder('USER_NODE_DEPT_PRIORITY', 'ASC');
	}

	private function injectRolePrioritySort(Query $query): void
	{
		$query->registerRuntimeField(
			new Reference(
				'USER_NODE_MEMBER_ROLE_REF',
				NodeMemberRoleTable::class,
				Join::on('this.USER_NODE_MEMBER.ID', 'ref.MEMBER_ID'),
				['join_type' => Join::TYPE_LEFT],
			),
		);

		$query->registerRuntimeField(
			new Reference(
				'USER_NODE_ROLE_REF',
				RoleTable::class,
				Join::on('this.USER_NODE_MEMBER_ROLE_REF.ROLE_ID', 'ref.ID'),
				['join_type' => Join::TYPE_LEFT],
			),
		);

		$query->registerRuntimeField(
			new ExpressionField(
				'USER_NODE_ROLE_PRIORITY',
				'MAX(%s)',
				['USER_NODE_ROLE_REF.PRIORITY'],
			),
		);
		$query->addOrder('USER_NODE_ROLE_PRIORITY', 'DESC');
	}

	private function getUserDepartmentNodeIds(int $userId): array
	{
		$rows = NodeMemberTable::query()
			->setSelect(['NODE_ID'])
			->where('ENTITY_TYPE', MemberEntityType::USER->value)
			->where('ENTITY_ID', $userId)
			->where('ACTIVE', 'Y')
			->where('NODE.TYPE', NodeEntityType::DEPARTMENT->value)
			->setGroup(['NODE_ID'])
			->fetchAll()
		;

		return array_map(
			static fn(array $row): int => (int)$row['NODE_ID'],
			$rows,
		);
	}

	private function addUserGroup(Query $query): void
	{
		if (!in_array('ID', $query->getGroup(), true))
		{
			$query->addGroup('ID');
		}
	}

	private function getSubQueryCacheKey(?array $nodeIds): string
	{
		if (empty($nodeIds))
		{
			return 'employees:all';
		}

		$nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
		sort($nodeIds);

		return 'employees:' . implode(',', $nodeIds);
	}
}
