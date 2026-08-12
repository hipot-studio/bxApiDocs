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
	/** @var array<int, list<int>> */
	private static array $directAccessCache = [];
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
	 * Used for READ/WRITE access and full-report subordinate read-side.
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
	 * Returns direct HR access users for legacy CTimeMan::GetDirectAccess.
	 *
	 * Unlike getSubordinateAccessUsers(), this method does not return the full subtree.
	 * It includes direct members of managed root nodes and report recipients of first-level child nodes.
	 */
	public function getDirectSubordinateAccessUsers(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (isset(self::$directAccessCache[$userId]))
		{
			return self::$directAccessCache[$userId];
		}

		self::$directAccessCache[$userId] = [];

		if (!Loader::includeModule('humanresources'))
		{
			return self::$directAccessCache[$userId];
		}

		try
		{
			$teamUsers = $this->getDirectAccessUsersForHeadNodes(
				$userId,
				NodeEntityType::TEAM,
				[StructureRole::TEAM_HEAD, StructureRole::TEAM_DEPUTY_HEAD],
				[NodeEntityType::TEAM],
				true,
			);
			$departmentUsers = $this->getDirectAccessUsersForHeadNodes(
				$userId,
				NodeEntityType::DEPARTMENT,
				[StructureRole::HEAD, StructureRole::DEPUTY_HEAD],
				[NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
				false,
			);

			$merged = array_merge($teamUsers, $departmentUsers);
			$excludedManagerIds = array_map('intval', \CTimeMan::GetUserManagers($userId));

			self::$directAccessCache[$userId] = $this->logic->filterMemberIds($merged, $userId, $excludedManagerIds);
		}
		catch (\Throwable)
		{
			self::$directAccessCache[$userId] = [];
		}

		return self::$directAccessCache[$userId];
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
	 * @param array<StructureRole> $headRoles
	 * @param array<NodeEntityType> $childNodeTypes
	 * @return list<int>
	 */
	private function getDirectAccessUsersForHeadNodes(
		int $userId,
		NodeEntityType $nodeType,
		array $headRoles,
		array $childNodeTypes,
		bool $filterChildRecipientsByReportsAuthority,
	): array
	{
		[$rootNodeIds, $deputyRootNodeIds] = $this->getRootNodeIdsWithAccessToReports($userId, $nodeType, $headRoles);
		if (empty($rootNodeIds))
		{
			return [];
		}

		$memberIds = $this->getDirectNodeMemberIds($rootNodeIds);
		$currentNodeIds = $rootNodeIds;
		$visitedNodeIds = array_fill_keys(array_map('intval', $rootNodeIds), true);
		while (!empty($currentNodeIds))
		{
			$childNodeIdsByType = $this->getDirectChildNodeIdsByType($currentNodeIds, $childNodeTypes);
			$nextNodeIds = [];

			foreach ($childNodeIdsByType as $childNodeTypeValue => $childNodeIds)
			{
				$childNodeType = NodeEntityType::tryFrom($childNodeTypeValue);
				if ($childNodeType === null)
				{
					continue;
				}

				[$recipientIdsByNode, $blockedNodeIds] = $this->getNodeReportRecipientIdsByNode(
					$childNodeIds,
					$childNodeType,
					$filterChildRecipientsByReportsAuthority,
				);

				$directAccessStep = $this->logic->buildDirectAccessStep(
					$childNodeIds,
					$recipientIdsByNode,
					$blockedNodeIds,
				);

				$memberIds = array_merge($memberIds, $directAccessStep['recipientIds']);

				$transparentNodeIds = array_values(array_filter(
					$directAccessStep['transparentNodeIds'],
					static fn(int $nodeId): bool => !isset($visitedNodeIds[$nodeId]),
				));
				if (!empty($transparentNodeIds))
				{
					$memberIds = array_merge($memberIds, $this->getDirectNodeMemberIds($transparentNodeIds));
					foreach ($transparentNodeIds as $transparentNodeId)
					{
						$visitedNodeIds[$transparentNodeId] = true;
					}

					$nextNodeIds = array_merge($nextNodeIds, $transparentNodeIds);
				}
			}

			$currentNodeIds = array_values(array_unique($nextNodeIds));
		}

		$excludedRootHeadIds = $this->getRootHeadUserIdsForDeputyNodes($deputyRootNodeIds, $nodeType);

		return $this->logic->filterMemberIds($memberIds, $userId, $excludedRootHeadIds);
	}

	/**
	 * @param list<int> $nodeIds
	 * @return list<int>
	 */
	private function getDirectNodeMemberIds(array $nodeIds): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

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
				->getAll()
				->getEntityIds(),
		));
	}

	/**
	 * @param list<int> $rootNodeIds
	 * @param array<NodeEntityType> $childNodeTypes
	 * @return array<string, list<int>>
	 */
	private function getDirectChildNodeIdsByType(array $rootNodeIds, array $childNodeTypes): array
	{
		if (empty($rootNodeIds))
		{
			return [];
		}

		$childFilter = count($childNodeTypes) === 1
			? NodeTypeFilter::fromNodeType($childNodeTypes[0])
			: NodeTypeFilter::fromNodeTypes($childNodeTypes);

		$childNodes = NodeDataBuilder::createWithFilter(
			new NodeFilter(
				idFilter: IdFilter::fromIds($rootNodeIds),
				entityTypeFilter: $childFilter,
				direction: Direction::CHILD,
				depthLevel: DepthLevel::FIRST,
			),
		)->getAll()->getValues();

		$result = [];
		foreach ($childNodes as $childNode)
		{
			$childNodeId = (int)($childNode->id ?? 0);
			$childNodeType = $childNode->type ?? null;
			if ($childNodeId <= 0 || !$childNodeType instanceof NodeEntityType)
			{
				continue;
			}

			$result[$childNodeType->value][] = $childNodeId;
		}

		foreach ($result as $nodeType => $nodeIds)
		{
			$result[$nodeType] = array_values(array_unique($nodeIds));
		}

		return $result;
	}

	/**
	 * @param list<int> $nodeIds
	 * @return array{0: array<int, list<int>>, 1: list<int>}
	 */
	private function getNodeReportRecipientIdsByNode(
		array $nodeIds,
		NodeEntityType $nodeType,
		bool $filterByReportsAuthority,
	): array
	{
		if (empty($nodeIds))
		{
			return [[], []];
		}

		$headRole = $nodeType === NodeEntityType::TEAM
			? StructureRole::TEAM_HEAD
			: StructureRole::HEAD;
		$deputyRole = $nodeType === NodeEntityType::TEAM
			? StructureRole::TEAM_DEPUTY_HEAD
			: StructureRole::DEPUTY_HEAD;

		$headIdsByNode = $this->getActiveNodeUserIdsByRoleByNode($nodeIds, $headRole);
		$deputyIdsByNode = $this->getActiveNodeUserIdsByRoleByNode($nodeIds, $deputyRole);

		if ($filterByReportsAuthority)
		{
			$reportsAuthorityByNode = Container::getNodeSettingsService()->getReportsAuthoritySettingsForNodes($nodeIds);
			[$headNodeIds, $deputyNodeIds] = $this->reportsAuthorityLogic->splitNodeIdsByAllowedTypes(
				$nodeIds,
				$reportsAuthorityByNode,
				$nodeType === NodeEntityType::TEAM
					? NodeSettingsAuthorityType::TeamHead
					: NodeSettingsAuthorityType::DepartmentHead,
				$nodeType === NodeEntityType::TEAM
					? NodeSettingsAuthorityType::TeamDeputy
					: NodeSettingsAuthorityType::DepartmentDeputy,
			);
		}
		else
		{
			$headNodeIds = $nodeIds;
			$deputyNodeIds = $nodeIds;
		}

		$headNodeIdMap = array_fill_keys(array_map('intval', $headNodeIds), true);
		$deputyNodeIdMap = array_fill_keys(array_map('intval', $deputyNodeIds), true);
		$recipientIdsByNode = [];
		$blockedNodeIds = [];

		foreach (array_values(array_unique(array_map('intval', $nodeIds))) as $nodeId)
		{
			$rawRecipientIds = array_merge($headIdsByNode[$nodeId] ?? [], $deputyIdsByNode[$nodeId] ?? []);
			if ($filterByReportsAuthority && !empty($rawRecipientIds))
			{
				$isHeadAllowed = isset($headNodeIdMap[$nodeId]);
				$isDeputyAllowed = isset($deputyNodeIdMap[$nodeId]);
				if (!$isHeadAllowed && !$isDeputyAllowed)
				{
					$blockedNodeIds[] = $nodeId;
					continue;
				}
			}

			if (isset($headNodeIdMap[$nodeId]))
			{
				$recipientIdsByNode[$nodeId] = array_merge(
					$recipientIdsByNode[$nodeId] ?? [],
					$headIdsByNode[$nodeId] ?? [],
				);
			}
			if (isset($deputyNodeIdMap[$nodeId]))
			{
				$recipientIdsByNode[$nodeId] = array_merge(
					$recipientIdsByNode[$nodeId] ?? [],
					$deputyIdsByNode[$nodeId] ?? [],
				);
			}

			if (isset($recipientIdsByNode[$nodeId]))
			{
				$recipientIdsByNode[$nodeId] = array_values(array_unique($recipientIdsByNode[$nodeId]));
			}
		}

		return [$recipientIdsByNode, array_values(array_unique($blockedNodeIds))];
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

		return $this->getActiveNodeUserIdsByRole($nodeIds, $headRole);
	}

	/**
	 * @param list<int> $nodeIds
	 * @return list<int>
	 */
	private function getActiveNodeUserIdsByRole(array $nodeIds, StructureRole $role): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

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
				->setStructureRoles([$role])
				->getAll()
				->getEntityIds(),
		));
	}

	/**
	 * @param list<int> $nodeIds
	 * @return array<int, list<int>>
	 */
	private function getActiveNodeUserIdsByRoleByNode(array $nodeIds, StructureRole $role): array
	{
		if (empty($nodeIds))
		{
			return [];
		}

		$members = (new NodeMemberDataBuilder())
			->addFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIds),
					),
					active: true,
				),
			)
			->setStructureRoles([$role])
			->getAll();

		$result = [];
		foreach ($members as $member)
		{
			$result[(int)$member->nodeId][] = (int)$member->entityId;
		}

		foreach ($result as $nodeId => $userIds)
		{
			$result[$nodeId] = array_values(array_unique($userIds));
		}

		return $result;
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
