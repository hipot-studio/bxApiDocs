<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeMemberRole;

final class StructurePositionDiff
{
	/**
	 * @param array<int, ?NodeMemberRole> $before Senior role by node id of every membership before the change.
	 * @param array<int, ?NodeMemberRole> $after Senior role by node id of every membership after the change.
	 * @param ?int $moveTargetNodeId Node the employee was moved into while already being its member.
	 * @param array<int, string> $nodeNames
	 */
	public function change(
		int $userId,
		array $before,
		array $after,
		?int $moveTargetNodeId,
		array $nodeNames,
	): ?StructurePositionChange
	{
		$removed = array_diff_key($before, $after);
		$added = array_diff_key($after, $before);
		$roleChanged = array_filter(
			array_intersect_key($before, $after),
			static fn(?NodeMemberRole $role, int $nodeId): bool => $role !== $after[$nodeId],
			ARRAY_FILTER_USE_BOTH,
		);
		$keptTarget = $moveTargetNodeId !== null && array_key_exists($moveTargetNodeId, $after)
			? [$moveTargetNodeId => $after[$moveTargetNodeId]]
			: []
		;

		if ($removed && $added)
		{
			[$type, $previousNodes, $newNodes] = [StructurePositionChangeType::Moved, $removed, $added];
		}
		elseif ($removed && $keptTarget)
		{
			[$type, $previousNodes, $newNodes] = [StructurePositionChangeType::Moved, $removed, $keptTarget];
		}
		elseif ($added)
		{
			[$type, $previousNodes, $newNodes] = [StructurePositionChangeType::Added, [], $added];
		}
		elseif ($removed)
		{
			[$type, $previousNodes, $newNodes] = [StructurePositionChangeType::Removed, $removed, []];
		}
		elseif ($roleChanged)
		{
			$type = self::isLeading(self::seniorRole(array_intersect_key($after, $roleChanged)))
				? StructurePositionChangeType::RoleAssigned
				: StructurePositionChangeType::RoleRevoked
			;
			[$previousNodes, $newNodes] = [$roleChanged, $roleChanged];
		}
		else
		{
			return null;
		}

		$previousRoles = array_intersect_key($before, $removed + $roleChanged);
		$newRoles = array_intersect_key($after, $added + $roleChanged + $keptTarget);

		return new StructurePositionChange(
			userId: $userId,
			type: $type,
			previousNodeNames: self::names(array_keys($previousNodes), $nodeNames),
			newNodeNames: self::names(array_keys($newNodes), $nodeNames),
			previousRole: self::seniorRole($previousRoles),
			newRole: self::seniorRole($newRoles),
		);
	}

	/**
	 * @param array<int, ?NodeMemberRole> $roles
	 */
	public static function seniorRole(array $roles): ?NodeMemberRole
	{
		$senior = null;
		foreach ($roles as $role)
		{
			if ($role !== null && ($senior === null || self::rank($role) < self::rank($senior)))
			{
				$senior = $role;
			}
		}

		return $senior;
	}

	private static function rank(NodeMemberRole $role): int
	{
		return match ($role)
		{
			NodeMemberRole::Head, NodeMemberRole::TeamHead => 0,
			NodeMemberRole::DeputyHead, NodeMemberRole::TeamDeputyHead => 1,
			NodeMemberRole::Employee, NodeMemberRole::TeamEmployee => 2,
		};
	}

	private static function isLeading(?NodeMemberRole $role): bool
	{
		return $role !== null && self::rank($role) < self::rank(NodeMemberRole::Employee);
	}

	/**
	 * @param list<int> $nodeIds
	 * @param array<int, string> $nodeNames
	 * @return array<int, string>
	 */
	private static function names(array $nodeIds, array $nodeNames): array
	{
		$names = [];
		foreach ($nodeIds as $nodeId)
		{
			$names[$nodeId] = $nodeNames[$nodeId] ?? '';
		}

		return $names;
	}
}
