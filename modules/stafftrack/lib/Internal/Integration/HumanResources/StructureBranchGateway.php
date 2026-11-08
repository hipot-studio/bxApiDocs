<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Internals\Service\Container as HrInternalContainer;
use Bitrix\HumanResources\Public\Service\Container as HrPublicContainer;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\Main\Loader;

class StructureBranchGateway
{
	/**
	 * @param int[] $userIds
	 * @return array<int, int[]>
	 */
	public function getDepartmentNodeIdsByUsers(array $userIds): array
	{
		$userIds = IdNormalizer::normalize($userIds);
		if (empty($userIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$members = HrPublicContainer::getNodeMemberService()->findAllByEntityIds(
				entityIds: $userIds,
				memberEntityType: MemberEntityType::USER,
				nodeTypes: [NodeEntityType::DEPARTMENT],
			);
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getDepartmentNodeIdsByUsers');

			return [];
		}

		$nodeIdsByUser = [];
		foreach ($members as $member)
		{
			$nodeIdsByUser[$member->entityId][] = $member->nodeId;
		}

		return array_map(
			static fn (array $nodeIds) => IdNormalizer::normalize($nodeIds),
			$nodeIdsByUser,
		);
	}

	/**
	 * @param int[] $nodeIds
	 * @return array<int, int[]>
	 */
	public function getAncestorNodeIds(array $nodeIds): array
	{
		$nodeIds = IdNormalizer::normalize($nodeIds);
		if (empty($nodeIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$parentIdsByNodeId = HrInternalContainer::getNodePathRepository()->findParentIdsByNodeIds($nodeIds);
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getAncestorNodeIds');

			return [];
		}

		return array_map(
			static fn (array $parentIds) => IdNormalizer::normalize($parentIds),
			$parentIdsByNodeId,
		);
	}

	/**
	 * @param int[] $nodeIds
	 * @return array<int, int[]>
	 */
	public function getManagerIdsByNodes(array $nodeIds): array
	{
		$nodeIds = IdNormalizer::normalize($nodeIds);
		if (empty($nodeIds) || !Loader::includeModule('humanresources'))
		{
			return [];
		}

		try
		{
			$members = NodeMemberDataBuilder::createWithFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(
						idFilter: IdFilter::fromIds($nodeIds),
						entityTypeFilter: NodeTypeFilter::fromNodeType(NodeEntityType::DEPARTMENT),
						depthLevel: DepthLevel::NONE,
					),
					roleFilter: RoleFilter::fromRoles(NodeMemberRole::Head, NodeMemberRole::DeputyHead),
				),
			)
				->getAll()
			;
		}
		catch (\Throwable $e)
		{
			self::logException($e, 'getManagerIdsByNodes');

			return [];
		}

		$managerIdsByNode = [];
		foreach ($members as $member)
		{
			$managerIdsByNode[$member->nodeId][] = $member->entityId;
		}

		return array_map(
			static fn (array $managerIds) => IdNormalizer::normalize($managerIds),
			$managerIdsByNode,
		);
	}

	private static function logException(\Throwable $e, string $method): void
	{
		AddMessage2Log(
			'StructureBranchGateway::' . $method . ' error: ' . $e->getMessage(),
			'stafftrack',
		);
	}
}
