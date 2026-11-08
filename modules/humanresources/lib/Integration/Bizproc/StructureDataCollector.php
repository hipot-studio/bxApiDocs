<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * Builds the data set of the bizproc structure data node: asks the reader for nodes and people, applies
 * the filters of the node settings and holds the ceilings.
 *
 * Every business emptiness is an answer, not a failure: the set comes back with the rows that were
 * gathered and a REASON_* code telling where the result ran out.
 */
final class StructureDataCollector
{
	public const REASON_NO_SOURCE = 'ERR-001';
	public const REASON_NO_NODES = 'ERR-002';
	public const REASON_NO_MEMBERS = 'ERR-003';
	public const REASON_ALL_FILTERED = 'ERR-004';
	public const REASON_UNAVAILABLE = 'ERR-005';
	public const REASON_TRUNCATED = 'ERR-006';

	public const NODE_LIMIT = 500;
	public const MEMBER_LINK_LIMIT = 1000;

	private readonly StructureDataReader $reader;
	private readonly StructureUserAvailability $availability;

	public function __construct(
		?StructureDataReader $reader = null,
		?StructureUserAvailability $availability = null,
		private readonly int $nodeLimit = self::NODE_LIMIT,
		private readonly int $memberLinkLimit = self::MEMBER_LINK_LIMIT,
	)
	{
		$this->reader = $reader ?? new StructureDataReader();
		$this->availability = $availability ?? new StructureUserAvailability();
	}

	/**
	 * @param list<NodeMemberRole> $explicitRoles roles of the ByRole member scope, ignored by the others
	 */
	public function collect(
		StructureSelection $selection,
		StructureMemberScope $memberScope,
		array $explicitRoles,
		bool $includeChildMembers,
		bool $excludeHeads,
		bool $excludeInactive,
		bool $deduplicateUsers,
	): StructureDataSet
	{
		if (!$this->reader->isAvailable($selection->nodeTypes))
		{
			return new StructureDataSet(reason: self::REASON_UNAVAILABLE);
		}

		if ($selection->mode !== StructureSelectionMode::All && $selection->sourceNodeIds === [])
		{
			return new StructureDataSet(reason: self::REASON_NO_SOURCE);
		}

		[$nodes, $truncated] = $this->readNodes($selection);
		if ($nodes === [])
		{
			return new StructureDataSet(reason: self::REASON_NO_NODES);
		}

		$owners = [];
		if ($includeChildMembers)
		{
			[$descendants, $descendantsTruncated] = $this->readDescendants($nodes);
			$owners = $this->mapToOwners($nodes, $descendants);
			$truncated = $truncated || $descendantsTruncated;
		}

		$roles = $this->resolveRoles($memberScope, $selection, $explicitRoles);
		// A head is dropped by not being asked for at all: read and then filtered away, it would spend the
		// ceiling of the read, and the employees past it would never be asked for.
		$askedRoles = $roles;
		if ($excludeHeads)
		{
			$roles = array_values(array_filter(
				$roles,
				static fn(NodeMemberRole $role): bool => !self::isHeadLike($role),
			));
		}

		// Nothing left to ask for: everybody the scope could have named is a head, so the filter is what
		// emptied the answer, and the run says so rather than claiming the nodes hold nobody.
		$emptiedByExcludeHeads = $roles === [] && $askedRoles !== [];

		$members = $roles === [] ? [] : $this->readMembers($nodes, $owners, $roles);
		$hadMembers = $members !== [];
		// The read came back full: the filters below may well bring it under the ceiling again, but what
		// they work on is no longer everything the nodes hold.
		$truncated = $truncated || count($members) > $this->memberLinkLimit;

		if ($excludeInactive)
		{
			$members = $this->keepAvailableMembers($members);
		}

		if (count($members) > $this->memberLinkLimit)
		{
			$members = array_slice($members, 0, $this->memberLinkLimit);
			$truncated = true;
		}

		$userIds = array_map(static fn(StructureMemberRow $row): int => $row->userId, $members);
		if ($deduplicateUsers)
		{
			$userIds = array_values(array_unique($userIds));
		}

		return new StructureDataSet(
			$nodes,
			$members,
			$userIds,
			$truncated,
			$this->resolveReason($memberScope, $hadMembers, $members, $truncated, $emptiedByExcludeHeads),
		);
	}

	/**
	 * @return array{0: list<StructureNodeRow>, 1: bool} the rows and whether the ceiling cut them
	 */
	private function readNodes(StructureSelection $selection): array
	{
		$limit = max(0, min($selection->limit, $this->nodeLimit));
		$rows = $this->reader->selectNodes(new StructureSelection(
			$selection->sourceNodeIds,
			$selection->mode,
			$selection->nodeTypes,
			$limit + 1,
		));

		return [array_slice($rows, 0, $limit), count($rows) > $limit];
	}

	/**
	 * Nodes below the selected ones: their people are attached to the selected ancestor, while the nodes
	 * themselves stay out of the result.
	 *
	 * The read asks for every kind of node whatever the setting asked for. The nodes are never published;
	 * they only carry the chain of parents the walk to the owner climbs, and narrowing the read by kind
	 * tears that chain apart - a team under a department keeps pointing at a department the answer no
	 * longer holds. People of the other kind do not leak in: the roles of the read are the roles of the
	 * kind asked for, and a role of the other kind is ignored.
	 *
	 * @param list<StructureNodeRow> $nodes
	 * @return array{0: array<int, int|null>, 1: bool} descendant id => parent id, and whether the ceiling cut them
	 */
	private function readDescendants(array $nodes): array
	{
		$links = $this->reader->selectNodeLinks(new StructureSelection(
			$this->topmostNodeIds($nodes),
			StructureSelectionMode::Branch,
			[NodeEntityType::DEPARTMENT, NodeEntityType::TEAM],
			count($nodes) + $this->nodeLimit + 1,
		));

		$selected = array_flip(array_map(static fn(StructureNodeRow $row): int => $row->id, $nodes));
		$descendants = array_diff_key($links, $selected);

		return [array_slice($descendants, 0, $this->nodeLimit, true), count($descendants) > $this->nodeLimit];
	}

	/**
	 * The selected nodes no other selected node stands above. A branch below one of them holds the branch
	 * below the other, so asking for both reads the very same nodes twice and the ceiling would go on
	 * those repeats.
	 *
	 * @param list<StructureNodeRow> $nodes
	 * @return list<int>
	 */
	private function topmostNodeIds(array $nodes): array
	{
		$selected = array_flip(array_map(static fn(StructureNodeRow $row): int => $row->id, $nodes));

		$topmost = [];
		foreach ($nodes as $row)
		{
			if ($row->parentId === null || !isset($selected[$row->parentId]))
			{
				$topmost[] = $row->id;
			}
		}

		return $topmost;
	}

	/**
	 * The walk climbs the parents until it reaches a selected node. The root of the structure has no parent
	 * at all, and a chain can end at a node the read did not hold, so both ends are an answer, not a
	 * failure: the descendant stays without an owner and its people are left out. The ceiling cannot put a
	 * node in that state on its own, because the nodes come back ordered by their distance from the node
	 * the walk started at.
	 *
	 * @param list<StructureNodeRow> $nodes
	 * @param array<int, int|null> $descendants descendant id => parent id
	 * @return array<int, StructureNodeRow> descendant id => the selected node its people belong to
	 */
	private function mapToOwners(array $nodes, array $descendants): array
	{
		$parents = $descendants;
		$selected = [];
		foreach ($nodes as $row)
		{
			$parents[$row->id] = $row->parentId;
			$selected[$row->id] = $row;
		}

		$owners = [];
		foreach (array_keys($descendants) as $nodeId)
		{
			$seen = [];
			$current = $parents[$nodeId] ?? null;
			while ($current !== null && !isset($selected[$current]) && !isset($seen[$current]))
			{
				$seen[$current] = true;
				$current = $parents[$current] ?? null;
			}

			if ($current !== null && isset($selected[$current]))
			{
				$owners[$nodeId] = $selected[$current];
			}
		}

		return $owners;
	}

	/**
	 * @param list<NodeMemberRole> $explicitRoles
	 * @return list<NodeMemberRole>
	 */
	private function resolveRoles(
		StructureMemberScope $memberScope,
		StructureSelection $selection,
		array $explicitRoles,
	): array
	{
		if ($memberScope !== StructureMemberScope::ByRole)
		{
			return $memberScope->roles($selection->nodeTypes);
		}

		return array_values(array_filter(
			StructureMemberScope::rolesOfNodeTypes($selection->nodeTypes),
			static fn(NodeMemberRole $role): bool => in_array($role, $explicitRoles, true),
		));
	}

	/**
	 * @param list<StructureNodeRow> $nodes
	 * @param array<int, StructureNodeRow> $owners
	 * @param list<NodeMemberRole> $roles
	 * @return list<StructureMemberRow> grouped by the selected node, in the order of $nodes
	 */
	private function readMembers(array $nodes, array $owners, array $roles): array
	{
		$nodeIds = array_map(static fn(StructureNodeRow $row): int => $row->id, $nodes);
		$rows = $this->reader->readMembers(
			[...$nodeIds, ...array_keys($owners)],
			$roles,
			$this->memberLinkLimit + 1,
		);

		$grouped = array_fill_keys($nodeIds, []);
		foreach ($rows as $row)
		{
			$owner = $owners[$row->nodeId] ?? null;
			if ($owner !== null)
			{
				$row = new StructureMemberRow($row->userId, $owner->id, $owner->name, $row->role);
			}
			elseif (!isset($grouped[$row->nodeId]))
			{
				continue;
			}

			$grouped[$row->nodeId][] = $row;
		}

		return array_merge(...array_values($grouped));
	}

	/**
	 * @param list<StructureMemberRow> $members
	 * @return list<StructureMemberRow>
	 */
	private function keepAvailableMembers(array $members): array
	{
		$userIds = array_values(array_unique(
			array_map(static fn(StructureMemberRow $row): int => $row->userId, $members),
		));
		$available = array_flip($this->availability->filterActive($userIds));

		return array_values(array_filter(
			$members,
			static fn(StructureMemberRow $row): bool => isset($available[$row->userId]),
		));
	}

	/**
	 * @param list<StructureMemberRow> $members what is left after the filters
	 */
	private function resolveReason(
		StructureMemberScope $memberScope,
		bool $hadMembers,
		array $members,
		bool $truncated,
		bool $emptiedByExcludeHeads,
	): ?string
	{
		return match (true)
		{
			$emptiedByExcludeHeads => self::REASON_ALL_FILTERED,
			!$hadMembers && $memberScope !== StructureMemberScope::None => self::REASON_NO_MEMBERS,
			$hadMembers && $members === [] => self::REASON_ALL_FILTERED,
			$truncated => self::REASON_TRUNCATED,
			default => null,
		};
	}

	private static function isHeadLike(NodeMemberRole $role): bool
	{
		return match ($role)
		{
			NodeMemberRole::Head,
			NodeMemberRole::DeputyHead,
			NodeMemberRole::TeamHead,
			NodeMemberRole::TeamDeputyHead => true,
			NodeMemberRole::Employee, NodeMemberRole::TeamEmployee => false,
		};
	}
}
