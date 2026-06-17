<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

use Bitrix\HumanResources;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\StructureRole;

final class ManagersResolver
{
	private ManagersLogic $logic;
	private ReportsAuthorityLogic $reportsAuthorityLogic;

	public function __construct(?ManagersLogic $logic = null, ?ReportsAuthorityLogic $reportsAuthorityLogic = null)
	{
		$this->logic = $logic ?? new ManagersLogic();
		$this->reportsAuthorityLogic = $reportsAuthorityLogic ?? new ReportsAuthorityLogic();
	}
	/**
	 * Returns team heads/deputies for the user from HR structure with role and node depth for hierarchy sort.
	 * Each item: ['id' => userId, 'role' => string, 'nodeDepth' => int]. nodeDepth: distance from root (higher = closer to user).
	 *
	 * @param int $userId
	 * @return array<array{id: int, role: string, nodeDepth: int}>
	 * @throws HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getUserTeamHeads(int $userId): array
	{
		$service = HumanResources\Public\Service\Container::getUserTeamService();

		$chains = $service->getTeamChainsByUserId($userId);
		if (empty($chains))
		{
			return [];
		}

		$nodeIds = [];
		foreach ($chains as $collection)
		{
			foreach ($collection as $node)
			{
				$nodeIds[] = (int)($node->id ?? 0);
			}
		}

		$nodeIds = array_values(array_unique(array_filter($nodeIds)));
		if (empty($nodeIds))
		{
			return [];
		}

		$nodeSettingsService = HumanResources\Public\Service\Container::getNodeSettingsService();
		$teamReportExceptionsByNode = $nodeSettingsService->getTeamReportExceptionsSettingsForNodes($nodeIds);
		if ($this->reportsAuthorityLogic->isUserExcludedFromTeamReports($userId, $teamReportExceptionsByNode))
		{
			return [];
		}

		$reportsAuthorityByNode = $nodeSettingsService->getReportsAuthoritySettingsForNodes($nodeIds);
		[$teamHeadNodeIds, $teamDeputyNodeIds] = $this->reportsAuthorityLogic->splitNodeIdsByAllowedTypes(
			$nodeIds,
			$reportsAuthorityByNode,
			HumanResources\Type\NodeSettingsAuthorityType::TeamHead,
			HumanResources\Type\NodeSettingsAuthorityType::TeamDeputy,
		);

		$teamHeadsByNode = $this->getActiveNodeUserIdsByRole($teamHeadNodeIds, StructureRole::TEAM_HEAD);
		$teamDeputiesByNode = $this->getActiveNodeUserIdsByRole($teamDeputyNodeIds, StructureRole::TEAM_DEPUTY_HEAD);

		return $this->logic->buildTeamHeads($chains, $teamHeadsByNode, $teamDeputiesByNode, $userId);
	}

	/**
	 * Returns department heads/deputies with role and node depth for hierarchy sort.
	 * Each item: ['id' => userId, 'role' => string, 'nodeDepth' => int].
	 *
	 * @param int $userId
	 * @return array<array{id: int, role: string, nodeDepth: int}>
	 * @throws HumanResources\Exception\WrongStructureItemException
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\ObjectPropertyException
	 * @throws \Bitrix\Main\SystemException
	 */
	public function getUserDepartmentHeads(int $userId): array
	{
		$chains = $this->getDepartmentChains($userId);
		if (empty($chains))
		{
			return [];
		}

		$nodeIds = [];
		foreach ($chains as $chain)
		{
			if (!empty($chain))
			{
				$nodeIds[] = (int)($chain[count($chain) - 1]->id ?? 0);
			}
		}

		$nodeIds = array_values(array_unique(array_filter($nodeIds)));
		if (empty($nodeIds))
		{
			return [];
		}

		$reportsAuthorityByNode = HumanResources\Service\Container::getNodeSettingsService()
			->getReportsAuthoritySettingsForNodes($nodeIds);
		[$departmentHeadNodeIds, $departmentDeputyNodeIds] = $this->reportsAuthorityLogic->splitNodeIdsByAllowedTypes(
			$nodeIds,
			$reportsAuthorityByNode,
			HumanResources\Type\NodeSettingsAuthorityType::DepartmentHead,
			HumanResources\Type\NodeSettingsAuthorityType::DepartmentDeputy,
		);

		$departmentHeadsByNode = $this->getActiveNodeUserIdsByRole($departmentHeadNodeIds, StructureRole::HEAD);
		$departmentDeputiesByNode = $this->getActiveNodeUserIdsByRole($departmentDeputyNodeIds, StructureRole::DEPUTY_HEAD);

		return $this->logic->buildDepartmentHeads($chains, $departmentHeadsByNode, $departmentDeputiesByNode, $userId);
	}

	/**
	 * Returns active user member ids grouped by node id for a specific structure role.
	 *
	 * @param list<int> $nodeIds
	 * @param StructureRole $role
	 * @return array<int, list<int>> nodeId => userIds
	 * @throws \Bitrix\Main\ArgumentException
	 */
	private function getActiveNodeUserIdsByRole(array $nodeIds, StructureRole $role): array
	{
		$nodeIds = array_values(array_unique(array_map('intval', $nodeIds)));
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
				)
			)
			->setStructureRoles([$role])
			->getAll();

		$result = [];
		foreach ($members as $member)
		{
			$result[$member->nodeId][] = (int)$member->entityId;
		}

		return $result;
	}


	private function getDepartmentChains(int $userId): array
	{
		if ($userId <= 0)
		{
			return [];
		}

		$memberFilter = new HumanResources\Builder\Structure\Filter\NodeMemberFilter(
			entityIdFilter: HumanResources\Builder\Structure\Filter\Column\EntityIdFilter::fromEntityId($userId),
			nodeFilter: new HumanResources\Builder\Structure\Filter\NodeFilter(
				entityTypeFilter: HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter::fromNodeType(
					NodeEntityType::DEPARTMENT
				),
			),
			findRelatedMembers: false,
		);

		$currentDepartmentMembers = (new HumanResources\Builder\Structure\NodeMemberDataBuilder())
			->addFilter($memberFilter)
			->getAll();

		$departmentNodeIds = $currentDepartmentMembers->getNodeIds();
		if (empty($departmentNodeIds))
		{
			return [];
		}

		$nodeFilter = new HumanResources\Builder\Structure\Filter\NodeFilter(
			idFilter: IdFilter::fromIds($departmentNodeIds),
			entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
			direction: Direction::ROOT,
			depthLevel: DepthLevel::FULL,
		);

		$allNodes = NodeDataBuilder::createWithFilter($nodeFilter)->getAll()->getValues();
		$nodesById = [];
		foreach ($allNodes as $node)
		{
			$nodesById[$node->id] = $node;
		}

		$chains = [];
		foreach ($departmentNodeIds as $nodeId)
		{
			$chain = [];
			$current = $nodesById[$nodeId] ?? null;
			while ($current)
			{
				array_unshift($chain, $current);
				$current = $nodesById[$current->parentId] ?? null;
			}
			$chains[] = $chain;
		}

		return $chains;
	}
}
