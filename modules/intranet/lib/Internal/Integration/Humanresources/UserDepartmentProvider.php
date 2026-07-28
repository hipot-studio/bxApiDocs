<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Humanresources;

use Bitrix\HumanResources\Internals\Service\Container as HumanResourcesContainer;
use Bitrix\Intranet\Entity\Collection\DepartmentCollection;
use Bitrix\Main\Loader;

final class UserDepartmentProvider
{
	private bool $isAvailable;
	private DepartmentRepository $departmentRepository;

	public function __construct()
	{
		$this->isAvailable = Loader::includeModule('humanresources');
		$this->departmentRepository = new DepartmentRepository();
	}

	/**
	 * @param array<int> $userIds
	 * @return array<int, DepartmentCollection>
	 */
	public function getMapByUserIds(array $userIds): array
	{
		if (!$this->isAvailable)
		{
			return [];
		}

		$userIds = $this->normalizeUserIds($userIds);

		if (empty($userIds))
		{
			return [];
		}

		$nodeIdsData = $this->getNodeIdsDataByUserIds($userIds);

		if (empty($nodeIdsData['nodeIds']))
		{
			return [];
		}

		$result = $this->createEmptyDepartmentMap($userIds);

		return $this->fillResultByUserNodeIds(
			$result,
			$nodeIdsData['userNodeIdMap'],
			$this->getDepartmentMapByNodeIds($nodeIdsData['nodeIds']),
		);
	}

	private function normalizeUserIds(array $userIds): array
	{
		return array_values(array_unique(array_filter(
			array_map('intval', $userIds),
			static fn(int $userId): bool => $userId > 0,
		)));
	}

	private function createEmptyDepartmentMap(array $userIds): array
	{
		$result = [];

		foreach ($userIds as $userId)
		{
			$result[$userId] = new DepartmentCollection();
		}

		return $result;
	}

	private function getNodeIdsDataByUserIds(array $userIds): array
	{
		$nodeMemberCollection = HumanResourcesContainer::getNodeMemberRepository()->findAllByEntityIds(
			$userIds,
		);

		if ($nodeMemberCollection->empty())
		{
			return [
				'userNodeIdMap' => [],
				'nodeIds' => [],
			];
		}

		$userNodeIdMap = [];
		$nodeIdMap = [];

		foreach ($nodeMemberCollection as $nodeMember)
		{
			$userNodeIdMap[$nodeMember->entityId][$nodeMember->nodeId] = $nodeMember->nodeId;
			$nodeIdMap[$nodeMember->nodeId] = $nodeMember->nodeId;
		}

		return [
			'userNodeIdMap' => $userNodeIdMap,
			'nodeIds' => array_values($nodeIdMap),
		];
	}

	private function getDepartmentMapByNodeIds(array $nodeIds): array
	{
		$departmentMap = [];
		$nodeCollection = HumanResourcesContainer::getNodeRepository()->findAllByIds($nodeIds);

		foreach ($nodeCollection as $node)
		{
			$departmentMap[$node->id] = $this->departmentRepository->createDepartmentFromNode($node);
		}

		return $departmentMap;
	}

	private function fillResultByUserNodeIds(
		array $result,
		array $userNodeIdMap,
		array $departmentMap,
	): array
	{
		foreach ($userNodeIdMap as $userId => $nodeIds)
		{
			$result[$userId] = $this->createDepartmentCollectionByNodeIds(
				$nodeIds,
				$departmentMap,
			);
		}

		return $result;
	}

	private function createDepartmentCollectionByNodeIds(
		array $nodeIds,
		array $departmentMap,
	): DepartmentCollection
	{
		$userDepartments = new DepartmentCollection();

		foreach ($nodeIds as $nodeId)
		{
			if (!isset($departmentMap[$nodeId]))
			{
				continue;
			}

			$userDepartments->add($departmentMap[$nodeId]);
		}

		return $userDepartments;
	}
}
