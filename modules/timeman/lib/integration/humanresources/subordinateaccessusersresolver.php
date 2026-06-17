<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;
use Bitrix\HumanResources\Type\StructureRole;
use Bitrix\Main\Loader;

final class SubordinateAccessUsersResolver
{
	/** @var array<int, list<int>> */
	private static array $cache = [];
	private SubordinateAccessUsersLogic $logic;
	private ReportsAuthorityLogic $reportsAuthorityLogic;

	public function __construct(
		?SubordinateAccessUsersLogic $logic = null,
		?ReportsAuthorityLogic $reportsAuthorityLogic = null,
	)
	{
		$this->logic = $logic ?? new SubordinateAccessUsersLogic();
		$this->reportsAuthorityLogic = $reportsAuthorityLogic ?? new ReportsAuthorityLogic();
	}

	/**
	 * Returns user IDs that current user can access as head/deputy: team subtree + department subtree (merged).
	 * Used for READ/WRITE access and GetDirectAccess.
	 */
	public function getSubordinateAccessUsers(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (isset(self::$cache[$userId]))
		{
			return self::$cache[$userId];
		}

		self::$cache[$userId] = [];

		if (!Loader::includeModule('humanresources'))
		{
			return self::$cache[$userId];
		}

		try
		{
			$teamUsers = $this->getAccessUsersForHeadNodes(
				$userId,
				NodeEntityType::TEAM,
				[StructureRole::TEAM_HEAD, StructureRole::TEAM_DEPUTY_HEAD],
				[NodeEntityType::TEAM],
				false,
				true,
			);
			$departmentUsers = $this->getAccessUsersForHeadNodes(
				$userId,
				NodeEntityType::DEPARTMENT,
				[StructureRole::HEAD, StructureRole::DEPUTY_HEAD],
				[NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
				true,
				false,
			);

			$merged = array_merge($teamUsers, $departmentUsers);
			$excludedManagerIds = array_map('intval', \CTimeMan::GetUserManagers($userId));

			self::$cache[$userId] = $this->logic->filterMemberIds($merged, $userId, $excludedManagerIds);
		}
		catch (\Throwable)
		{
			self::$cache[$userId] = [];
		}

		return self::$cache[$userId];
	}

	/**
	 * Shared logic: find nodes where user is head/deputy, build subtree, optionally filter by ReportsAuthority, return member user IDs.
	 *
	 * @param array<StructureRole> $headRoles
	 * @param array<NodeEntityType> $childNodeTypes types to include in subtree (fromNodeTypes)
	 * @param bool $includeRootNodes if true, head node IDs are included in node list (for department)
	 * @param bool $filterByReportsAuthority if true, filter nodes by team/department ReportsAuthority before collecting members
	 * @return list<int>
	 */
	private function getAccessUsersForHeadNodes(
		int $userId,
		NodeEntityType $nodeType,
		array $headRoles,
		array $childNodeTypes,
		bool $includeRootNodes,
		bool $filterByReportsAuthority,
	): array
	{
		[$rootNodeIds, $deputyRootNodeIds] = $this->getRootNodeIdsWithAccessToReports($userId, $nodeType, $headRoles);
		if (empty($rootNodeIds))
		{
			return [];
		}

		$childFilter = count($childNodeTypes) === 1
			? NodeTypeFilter::fromNodeType($childNodeTypes[0])
			: NodeTypeFilter::fromNodeTypes($childNodeTypes);

		$queue = NodeDataBuilder::createWithFilter(
			new NodeFilter(
				idFilter: IdFilter::fromIds($rootNodeIds),
				entityTypeFilter: $childFilter,
				direction: Direction::CHILD,
				depthLevel: DepthLevel::FULL,
			),
		)->getAll()->getValues();

		$nodeIds = $this->logic->buildNodeIdsFromQueue($rootNodeIds, $queue, $includeRootNodes);

		if ($filterByReportsAuthority)
		{
			$nodeIds = $nodeType === NodeEntityType::TEAM
				? $this->filterNodesWithTeamAuthority($nodeIds)
				: $this->filterNodesWithDepartmentAuthority($nodeIds);
		}
		if (empty($nodeIds))
		{
			return [];
		}

		$membersIds = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIds),
					),
					active: true,
				)
			)
			->getAll()->getEntityIds();

		$excludedRootHeadIds = $this->getRootHeadUserIdsForDeputyNodes($deputyRootNodeIds, $nodeType);

		return $this->logic->filterMemberIds($membersIds, $userId, $excludedRootHeadIds);
	}

	/**
	 * Deputy must not get report access until the HR feature is enabled and the node explicitly allows deputy recipients.
	 *
	 * Heads keep the previous behavior and are filtered later by existing subtree logic.
	 *
	 * @param array<StructureRole> $headRoles
	 * @return array{0: list<int>, 1: list<int>}
	 */
	private function getRootNodeIdsWithAccessToReports(
		int $userId,
		NodeEntityType $nodeType,
		array $headRoles,
	): array
	{
		$isDeputyReportsAvailable = Feature::instance()->isDeputyGetReportsAvailable();
		$headNodeIds = [];
		$deputyNodeIds = [];

		foreach ($headRoles as $role)
		{
			if ($this->isDeputyRole($role))
			{
				if (!$isDeputyReportsAvailable)
				{
					continue;
				}

				$nodeIds = $this->getRootNodeIdsByRole($userId, $nodeType, $role);
				$deputyNodeIds = array_merge($deputyNodeIds, $nodeIds);
			}
			else
			{
				$nodeIds = $this->getRootNodeIdsByRole($userId, $nodeType, $role);
				$headNodeIds = array_merge($headNodeIds, $nodeIds);
			}
		}

		$headNodeIds = array_values(array_unique(array_map('intval', $headNodeIds)));
		$deputyNodeIds = array_values(array_unique(array_map('intval', $deputyNodeIds)));

		if (empty($deputyNodeIds))
		{
			return [$headNodeIds, []];
		}

		$reportsAuthorityByNode = Container::getNodeSettingsService()->getReportsAuthoritySettingsForNodes($deputyNodeIds);
		$allowedDeputyType = $nodeType === NodeEntityType::TEAM
			? NodeSettingsAuthorityType::TeamDeputy
			: NodeSettingsAuthorityType::DepartmentDeputy;

		$deputyNodeIds = $this->reportsAuthorityLogic->filterNodeIdsByAllowedTypes(
			$deputyNodeIds,
			$reportsAuthorityByNode,
			[$allowedDeputyType],
		);

		$deputyOnlyNodeIds = array_values(array_diff($deputyNodeIds, $headNodeIds));

		return [
			array_values(array_unique(array_merge($headNodeIds, $deputyNodeIds))),
			$deputyOnlyNodeIds,
		];
	}

	/**
	 * Deputy may see reports of members from the allowed root node, but must not see reports of the root node head itself.
	 *
	 * @param list<int> $nodeIds
	 * @return list<int>
	 */
	private function getRootHeadUserIdsForDeputyNodes(array $nodeIds, NodeEntityType $nodeType): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

		$headRole = $nodeType === NodeEntityType::TEAM
			? StructureRole::TEAM_HEAD
			: StructureRole::HEAD;

		return array_values(array_unique(
			(new NodeMemberDataBuilder())
				->addFilter(
					new NodeMemberFilter(
						entityType: MemberEntityType::USER,
						nodeFilter: new NodeFilter(
							idFilter: IdFilter::fromIds($nodeIds),
						),
						active: true,
					),
				)
				->setStructureRoles([$headRole])
				->getAll()
				->getEntityIds(),
		));
	}

	private function getRootNodeIdsByRole(int $userId, NodeEntityType $nodeType, StructureRole $role): array
	{
		$nodeFilter = new NodeFilter(
			entityTypeFilter: NodeTypeFilter::fromNodeType($nodeType),
		);

		$memberFilter = new NodeMemberFilter(
			entityIdFilter: \Bitrix\HumanResources\Builder\Structure\Filter\Column\EntityIdFilter::fromEntityId($userId),
			nodeFilter: $nodeFilter,
			findRelatedMembers: false,
		);

		return (new NodeMemberDataBuilder())
			->addFilter($memberFilter)
			->setStructureRoles([$role])
			->getAll()
			->getNodeIds();
	}

	private function isDeputyRole(StructureRole $role): bool
	{
		return in_array($role, [StructureRole::DEPUTY_HEAD, StructureRole::TEAM_DEPUTY_HEAD], true);
	}

	private function filterNodesWithTeamAuthority(array $nodeIds): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

		$reportsAuthorityList = Container::getNodeSettingsService()->getReportsAuthoritySettingsForNodes($nodeIds);

		return $this->reportsAuthorityLogic->filterNodeIdsByAllowedTypes(
			$nodeIds,
			$reportsAuthorityList,
			[
				\Bitrix\HumanResources\Type\NodeSettingsAuthorityType::TeamDeputy,
				\Bitrix\HumanResources\Type\NodeSettingsAuthorityType::TeamHead,
			],
		);
	}

	private function filterNodesWithDepartmentAuthority(array $nodeIds): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

		$reportsAuthorityList = Container::getNodeSettingsService()->getReportsAuthoritySettingsForNodes($nodeIds);

		return $this->reportsAuthorityLogic->filterNodeIdsByAllowedTypes(
			$nodeIds,
			$reportsAuthorityList,
			[
				\Bitrix\HumanResources\Type\NodeSettingsAuthorityType::DepartmentDeputy,
				\Bitrix\HumanResources\Type\NodeSettingsAuthorityType::DepartmentHead,
			],
		);
	}
}
