<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

final class SubordinateAccessUsersLogic
{
	/**
	 * @param list<int> $rootNodeIds
	 * @param iterable $queue
	 * @return list<int>
	 */
	public function buildNodeIdsFromQueue(
		array $rootNodeIds,
		iterable $queue,
		bool $includeRootNodes,
	): array {
		$visitedNodeIds = [];
		$nodeIds = $includeRootNodes ? $rootNodeIds : [];
		foreach ($queue as $node)
		{
			$nodeId = (int)($node->id ?? 0);
			if ($nodeId <= 0 || isset($visitedNodeIds[$nodeId]))
			{
				continue;
			}
			$visitedNodeIds[$nodeId] = true;
			$nodeIds[] = $nodeId;
		}

		return array_values(array_unique($nodeIds));
	}

	/**
	 * @param list<int> $memberIds
	 * @param list<int> $excludedMemberIds
	 * @return list<int>
	 */
	public function filterMemberIds(array $memberIds, int $userId, array $excludedMemberIds = []): array
	{
		$excludedMemberIds[] = $userId;
		$excludedMemberIds = array_values(array_unique(array_map('intval', $excludedMemberIds)));
		$excludedMemberIdsMap = array_fill_keys($excludedMemberIds, true);
		$result = array_filter(
			$memberIds,
			static fn($memberId) => !isset($excludedMemberIdsMap[(int)$memberId]),
		);

		return array_values(array_unique($result));
	}

	/**
	 * @param list<int> $nodeIds
	 * @param array<int, list<int>> $recipientIdsByNode
	 * @param list<int> $blockedNodeIds
	 * @return array{recipientIds: list<int>, transparentNodeIds: list<int>}
	 */
	public function buildDirectAccessStep(
		array $nodeIds,
		array $recipientIdsByNode,
		array $blockedNodeIds = [],
	): array
	{
		$blockedNodeIds = array_values(array_unique(array_map('intval', $blockedNodeIds)));
		$blockedNodeIdsMap = array_fill_keys($blockedNodeIds, true);
		$recipientIds = [];
		$transparentNodeIds = [];

		foreach (array_values(array_unique(array_map('intval', $nodeIds))) as $nodeId)
		{
			if ($nodeId <= 0 || isset($blockedNodeIdsMap[$nodeId]))
			{
				continue;
			}

			$nodeRecipientIds = array_values(array_unique(array_map(
				'intval',
				$recipientIdsByNode[$nodeId] ?? [],
			)));

			if (!empty($nodeRecipientIds))
			{
				$recipientIds = array_merge($recipientIds, $nodeRecipientIds);
			}
			else
			{
				$transparentNodeIds[] = $nodeId;
			}
		}

		return [
			'recipientIds' => array_values(array_unique($recipientIds)),
			'transparentNodeIds' => array_values(array_unique($transparentNodeIds)),
		];
	}
}
