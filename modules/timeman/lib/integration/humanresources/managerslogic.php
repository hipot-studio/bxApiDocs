<?php

declare(strict_types=1);

namespace Bitrix\Timeman\Integration\Humanresources;

final class ManagersLogic
{
	public const ROLE_TEAM_DEPUTY_HEAD = 'TEAM_DEPUTY_HEAD';
	public const ROLE_DEPUTY_HEAD = 'DEPUTY_HEAD';
	public const ROLE_TEAM_HEAD = 'TEAM_HEAD';
	public const ROLE_HEAD = 'HEAD';

	/**
	 * Sorts managers with roles: first by hierarchy (closer = higher nodeDepth first), then by role (deputy, then head).
	 *
	 * @param array<int, array{id: int, role: string, nodeDepth: int}> $managersWithRoles
	 * @return array<int, array{id: int, role: string, nodeDepth: int}>
	 */
	public function sortManagersWithRolesByReportRecipientPriority(array $managersWithRoles): array
	{
		$list = array_values($managersWithRoles);
		usort($list, static function ($a, $b) {
			$depthA = $a['nodeDepth'] ?? -1;
			$depthB = $b['nodeDepth'] ?? -1;
			if ($depthA !== $depthB)
			{
				return $depthB <=> $depthA;
			}

			$roleOrder = static function (array $item): int {
				$role = $item['role'] ?? null;
				if (!is_string($role))
				{
					return 2;
				}

				if (
					$role === self::ROLE_TEAM_DEPUTY_HEAD
					|| $role === self::ROLE_DEPUTY_HEAD
				)
				{
					return 0;
				}

				return 1;
			};

			return $roleOrder($a) <=> $roleOrder($b);
		});

		return $list;
	}

	/**
	 * Pure function: adds a recipient to the heads list or updates existing entry (deputy role and max node depth).
	 *
	 * @param array<int, array{id: int, role: string, nodeDepth: int}> $heads
	 * @param array<int, int> $headIdsToIndex id => index in $heads
	 * @return array{0: array<int, array{id: int, role: string, nodeDepth: int}>, 1: array<int, int>}
	 */
	public function mergeRecipientIntoHeadsList(
		array $heads,
		array $headIdsToIndex,
		int $headId,
		string $role,
		int $nodeDepth,
	): array
	{
		if (isset($headIdsToIndex[$headId]))
		{
			$idx = $headIdsToIndex[$headId];
			if ($role === self::ROLE_TEAM_DEPUTY_HEAD || $role === self::ROLE_DEPUTY_HEAD)
			{
				$heads[$idx]['role'] = $role;
			}
			$heads[$idx]['nodeDepth'] = max($heads[$idx]['nodeDepth'], $nodeDepth);
		}
		else
		{
			$headIdsToIndex[$headId] = count($heads);
			$heads[] = ['id' => $headId, 'role' => $role, 'nodeDepth' => $nodeDepth];
		}

		return [$heads, $headIdsToIndex];
	}

	/**
	 * Pure builder for team heads/deputies from chains and preloaded node user ids.
	 *
	 * @param iterable $chains
	 * @param array<int, list<int>> $teamHeadsByNode
	 * @param array<int, list<int>> $teamDeputiesByNode
	 * @return array<int, array{id: int, role: string, nodeDepth: int}>
	 */
	public function buildTeamHeads(
		iterable $chains,
		array $teamHeadsByNode,
		array $teamDeputiesByNode,
		int $userId,
	): array
	{
		$chainsArray = $this->normalizeChains($chains);
		if (empty($chainsArray))
		{
			return [];
		}

		$numChains = count($chainsArray);
		$heads = [];
		$headIdsToIndex = [];
		foreach ($chainsArray as $chainIndex => $nodes)
		{
			$chainLength = count($nodes);
			foreach ($nodes as $index => $node)
			{
				$nodeId = (int)($node->id ?? 0);
				$recipients = $this->resolveRecipientsForNode(
					$nodeId,
					$teamHeadsByNode,
					$teamDeputiesByNode,
					$userId,
				);
				if ($recipients === null)
				{
					continue;
				}
				[$headIds, $deputyIds] = $recipients;

				$levelInChain = $chainLength - 1 - $index;
				$nodeDepth = $levelInChain * ($numChains + 1) + ($numChains - 1 - $chainIndex);
				foreach ($deputyIds as $headId)
				{
					[$heads, $headIdsToIndex] = $this->mergeRecipientIntoHeadsList(
						$heads,
						$headIdsToIndex,
						(int)$headId,
						self::ROLE_TEAM_DEPUTY_HEAD,
						$nodeDepth,
					);
				}

				foreach ($headIds as $headId)
				{
					[$heads, $headIdsToIndex] = $this->mergeRecipientIntoHeadsList(
						$heads,
						$headIdsToIndex,
						(int)$headId,
						self::ROLE_TEAM_HEAD,
						$nodeDepth,
					);
				}
			}
		}

		return $heads;
	}

	/**
	 * Pure builder for department heads/deputies from chains and preloaded node user ids.
	 *
	 * @param array<int, array<int, object>> $chains
	 * @param array<int, list<int>> $departmentHeadsByNode
	 * @param array<int, list<int>> $departmentDeputiesByNode
	 * @return array<int, array{id: int, role: string, nodeDepth: int}>
	 */
	public function buildDepartmentHeads(
		array $chains,
		array $departmentHeadsByNode,
		array $departmentDeputiesByNode,
		int $userId,
	): array
	{
		if (empty($chains))
		{
			return [];
		}

		$numChains = count($chains);
		$heads = [];
		$headIdsToIndex = [];
		foreach ($chains as $chainIndex => $chain)
		{
			if (empty($chain))
			{
				continue;
			}
			$node = $chain[count($chain) - 1];
			$nodeId = (int)($node->id ?? 0);
			$recipients = $this->resolveRecipientsForNode(
				$nodeId,
				$departmentHeadsByNode,
				$departmentDeputiesByNode,
				$userId,
			);
			if ($recipients === null)
			{
				continue;
			}
			[$headIds, $deputyIds] = $recipients;

			$nodeDepth = $numChains - 1 - $chainIndex;
			foreach ($deputyIds as $headId)
			{
				[$heads, $headIdsToIndex] = $this->mergeRecipientIntoHeadsList(
					$heads,
					$headIdsToIndex,
					(int)$headId,
					self::ROLE_DEPUTY_HEAD,
					$nodeDepth,
				);
			}

			foreach ($headIds as $headId)
			{
				[$heads, $headIdsToIndex] = $this->mergeRecipientIntoHeadsList(
					$heads,
					$headIdsToIndex,
					(int)$headId,
					self::ROLE_HEAD,
					$nodeDepth,
				);
			}
		}

		return $heads;
	}

	/**
	 * @param iterable $chains
	 * @return array<int, array<int, object>>
	 */
	private function normalizeChains(iterable $chains): array
	{
		$result = [];
		foreach ($chains as $collection)
		{
			$nodes = [];
			foreach ($collection as $node)
			{
				$nodes[] = $node;
			}
			$result[] = $nodes;
		}

		return $result;
	}

	/**
	 * @param int $nodeId
	 * @param array<int, list<int>> $headsByNode
	 * @param array<int, list<int>> $deputiesByNode
	 * @return array{0: list<int>, 1: list<int>}|null
	 */
	public function resolveRecipientsForNode(
		int $nodeId,
		array $headsByNode,
		array $deputiesByNode,
		int $userId,
	): ?array
	{
		if ($nodeId <= 0)
		{
			return null;
		}

		$headIds = $headsByNode[$nodeId] ?? [];
		$deputyIds = $deputiesByNode[$nodeId] ?? [];
		if (empty($headIds) && empty($deputyIds))
		{
			return null;
		}

		$allRecipientIds = array_values(array_unique(array_merge($headIds, $deputyIds)));
		if (in_array($userId, $allRecipientIds, true))
		{
			return null;
		}

		return [$headIds, $deputyIds];
	}
}
