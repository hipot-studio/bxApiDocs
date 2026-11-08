<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\Bizproc;

use Bitrix\HumanResources\Builder\Structure\Filter\Column\IdFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\Node\NodeTypeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\Column\RoleFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeFilter;
use Bitrix\HumanResources\Builder\Structure\Filter\NodeMemberFilter;
use Bitrix\HumanResources\Builder\Structure\NodeDataBuilder;
use Bitrix\HumanResources\Builder\Structure\NodeMemberDataBuilder;
use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Enum\DepthLevel;
use Bitrix\HumanResources\Enum\Direction;
use Bitrix\HumanResources\Enum\NodeActiveFilter;
use Bitrix\HumanResources\Internals\Service\Container as InternalContainer;
use Bitrix\HumanResources\Item\Node;
use Bitrix\HumanResources\Public\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\MemberEntityType;
use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;

/**
 * The reads of the org structure the bizproc structure data node needs. The root of the structure is
 * level 1, a path holds the names from it down to the node, and a read gives at most the rows asked for,
 * ordered by level and then by id.
 */
class StructureDataReader
{
	/** Walking down to no bound at all: NodeFilter anchors a read at the root only when given a depth. */
	private const UNBOUNDED_DEPTH = PHP_INT_MAX;

	/**
	 * The access code of the default columns is left out on purpose: it is a table of its own, a row per
	 * code, and the join to it turns one node into as many rows as it holds codes.
	 */
	private const NODE_COLUMNS = ['ID', 'TYPE', 'PARENT_ID', 'NAME', 'GLOBAL_ACTIVE'];

	/** @var array<string, bool> node types of the read => availability, memoized for the request */
	private static array $availability = [];

	private ?NodeMemberDataBuilder $memberBuilder = null;

	public static function resetAvailabilityCache(): void
	{
		self::$availability = [];
	}

	/**
	 * @param list<NodeEntityType> $nodeTypes
	 */
	public function isAvailable(array $nodeTypes): bool
	{
		return self::$availability[self::typeKey($nodeTypes)] ??= $this->checkAvailability($nodeTypes);
	}

	/**
	 * Node ids behind the tokens the activity read off its source setting: bare ids of an expression and
	 * access codes of the structure. A token naming no node, an id of another kind and an id the structure
	 * does not hold are dropped. $limit bounds the read itself, not only its answer: the setting holds an
	 * expression, and a list it computes has no size of its own.
	 *
	 * @param list<int|string> $tokens
	 * @param list<NodeEntityType> $nodeTypes
	 * @return list<int> unique, in the order of $tokens, at most $limit of them
	 */
	public function resolveNodeIds(array $tokens, array $nodeTypes, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$accessCodes = [];
		foreach ($tokens as $token)
		{
			$code = $this->toAccessCode($token);
			if ($code !== null)
			{
				$accessCodes[$code] = $code;
			}

			if (count($accessCodes) >= $limit)
			{
				break;
			}
		}

		// What a code names is the structure to say; the order of the picked values is ours to keep, and an
		// id the structure named on its own goes after the ones the codes themselves could be read as.
		$resolved = $this->readNodeIdsOfCodes($accessCodes);
		$named = array_map($this->nodeIdOfAccessCode(...), array_values($accessCodes));
		$nodeIds = array_values(array_intersect(array_unique($named), $resolved));
		$nodeIds = array_merge($nodeIds, array_values(array_diff($resolved, $nodeIds)));

		return $this->keepNodesOfTypes(array_slice($nodeIds, 0, $limit), $nodeTypes);
	}

	/**
	 * @return list<StructureNodeRow> at most $selection->limit of them, a node switched off or deleted absent
	 */
	public function selectNodes(StructureSelection $selection): array
	{
		$nodes = $this->readSelectedNodes($selection);
		if (!$nodes)
		{
			return [];
		}

		$rows = $this->buildNodeRows($nodes);
		usort(
			$rows,
			static fn(StructureNodeRow $left, StructureNodeRow $right): int
				=> [$left->level, $left->id] <=> [$right->level, $right->id],
		);

		return array_slice($rows, 0, $selection->limit);
	}

	/**
	 * The same nodes as {@see self::selectNodes()}, as the link of each to its parent and nothing else: a
	 * walk to the node above needs no more, and building the rows would cost a second read of the ancestors.
	 * The order stays the one the ceiling cuts in, so a node of the answer never loses the nodes between it
	 * and the anchor of the walk.
	 *
	 * @return array<int, int|null> node id => parent id, null at a node with no parent
	 */
	public function selectNodeLinks(StructureSelection $selection): array
	{
		$links = [];
		foreach ($this->readSelectedNodes($selection) as $nodeId => $node)
		{
			$links[$nodeId] = (int)$node->parentId > 0 ? (int)$node->parentId : null;
		}

		return array_slice($links, 0, $selection->limit, true);
	}

	/**
	 * Active members of the nodes holding the roles asked for, a row per link person to node to role. A
	 * role of another kind of node is ignored; virtual users are never returned.
	 *
	 * @param list<int> $nodeIds
	 * @param list<NodeMemberRole> $roles
	 * @param int $limit the ceiling of the read, shared by the roles asked for
	 * @return list<StructureMemberRow>
	 */
	public function readMembers(array $nodeIds, array $roles, int $limit): array
	{
		$nodeIds = $this->normalizeIds($nodeIds);
		$roles = $this->orderRoles($roles);
		if ($limit <= 0 || !$nodeIds || !$roles)
		{
			return [];
		}

		$names = $this->readNames($nodeIds);

		// One read per role: the role of a row is then known by construction, while a single read would
		// answer with memberships whose roles still had to be asked for one by one.
		$userIdsByNodeAndRole = [];
		$remaining = $limit;
		foreach ($roles as $role)
		{
			if ($remaining <= 0)
			{
				break;
			}

			foreach ($this->readRoleMembers($nodeIds, $role, $remaining) as $nodeId => $userIds)
			{
				$remaining -= count($userIds);
				sort($userIds);
				$userIdsByNodeAndRole[$nodeId][$role->value] = $userIds;
			}
		}

		$rows = [];
		foreach ($nodeIds as $nodeId)
		{
			foreach ($roles as $role)
			{
				foreach ($userIdsByNodeAndRole[$nodeId][$role->value] ?? [] as $userId)
				{
					$rows[] = new StructureMemberRow($userId, $nodeId, $names[$nodeId] ?? '', $role);
				}
			}
		}

		return $rows;
	}

	/**
	 * A portal without cross-functional teams still has a structure of departments to read.
	 *
	 * @param list<NodeEntityType> $nodeTypes
	 */
	private function checkAvailability(array $nodeTypes): bool
	{
		if (!Storage::instance()->isCompanyStructureConverted())
		{
			return false;
		}

		return !in_array(NodeEntityType::TEAM, $nodeTypes, true)
			|| Feature::instance()->isCrossFunctionalTeamsAvailable()
		;
	}

	/**
	 * @return array<int, Node> nodeId => node
	 */
	private function readSelectedNodes(StructureSelection $selection): array
	{
		if ($selection->limit <= 0)
		{
			return [];
		}

		$sourceNodeIds = $this->boundSources($this->normalizeIds($selection->sourceNodeIds), $selection);
		if (!$sourceNodeIds && $selection->mode !== StructureSelectionMode::All)
		{
			return [];
		}

		$nodes = $this->readNodes(
			$this->buildSelectionFilter($selection, $sourceNodeIds),
			$this->walkCeiling($selection),
		);

		return $selection->mode === StructureSelectionMode::Parent
			? $this->readParents($nodes, $selection->nodeTypes)
			: $nodes
		;
	}

	/**
	 * @param list<int> $sourceNodeIds
	 */
	private function buildSelectionFilter(StructureSelection $selection, array $sourceNodeIds): NodeFilter
	{
		$types = NodeTypeFilter::fromNodeTypes($selection->nodeTypes);

		return match ($selection->mode)
		{
			StructureSelectionMode::Element => new NodeFilter(
				idFilter: IdFilter::fromIds($sourceNodeIds),
				entityTypeFilter: $types,
				depthLevel: DepthLevel::NONE,
				active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
			),
			// With no ids to start from the structure answers from its root, and that anchor is what makes
			// the read bounded and what the ceiling then cuts by.
			StructureSelectionMode::All => new NodeFilter(
				entityTypeFilter: $types,
				depthLevel: self::UNBOUNDED_DEPTH,
				active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
			),
			// The source nodes themselves, not the parents the walk is after: a Direction::ROOT read bounded
			// by a depth asks the structure for the depth of every source one by one, and the parents are a
			// read of their own away anyway. The kind is asked of the parents, so a source of another kind
			// names its parent all the same.
			StructureSelectionMode::Parent => new NodeFilter(
				idFilter: IdFilter::fromIds($sourceNodeIds),
				depthLevel: DepthLevel::NONE,
				active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
			),
			StructureSelectionMode::Children => new NodeFilter(
				idFilter: IdFilter::fromIds($sourceNodeIds),
				entityTypeFilter: $types,
				direction: Direction::CHILD,
				depthLevel: DepthLevel::FIRST,
				active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
			),
			StructureSelectionMode::Branch => new NodeFilter(
				idFilter: IdFilter::fromIds($sourceNodeIds),
				entityTypeFilter: $types,
				direction: Direction::CHILD,
				depthLevel: DepthLevel::FULL,
				active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
			),
		};
	}

	/**
	 * @param array<int, Node> $sources
	 * @param list<NodeEntityType> $nodeTypes
	 * @return array<int, Node>
	 */
	private function readParents(array $sources, array $nodeTypes): array
	{
		$parentIds = [];
		foreach ($sources as $source)
		{
			$parentId = (int)$source->parentId;
			if ($parentId > 0)
			{
				$parentIds[$parentId] = $parentId;
			}
		}

		return $parentIds
			? $this->readNodes(
				new NodeFilter(
					idFilter: IdFilter::fromIds(array_values($parentIds)),
					entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
					active: NodeActiveFilter::ONLY_GLOBAL_ACTIVE,
				),
			)
			: []
		;
	}

	/**
	 * Level and path come off the materialized paths: the ancestors of the whole set in one read, their
	 * distance from the root in a second one to order them, their names in a third.
	 *
	 * @param array<int, Node> $nodes
	 * @return list<StructureNodeRow>
	 */
	private function buildNodeRows(array $nodes): array
	{
		$pathRepository = InternalContainer::getNodePathRepository();
		$ancestorsByNode = $pathRepository->findParentIdsByNodeIds(array_keys($nodes));
		$ancestorIds = array_values(array_unique(array_merge(...array_values($ancestorsByNode)) ?: []));
		$depths = $pathRepository->getAbsoluteDepthMap($ancestorIds);
		$names = $ancestorIds ? $this->readNames($ancestorIds) : [];

		$rows = [];
		foreach ($nodes as $nodeId => $node)
		{
			$chain = $ancestorsByNode[$nodeId] ?? [];
			usort($chain, static fn(int $left, int $right): int => ($depths[$left] ?? 0) <=> ($depths[$right] ?? 0));

			$path = '';
			foreach ($chain as $ancestorId)
			{
				$path .= '/' . ($names[$ancestorId] ?? '');
			}

			$rows[] = new StructureNodeRow(
				$nodeId,
				(string)$node->name,
				$node->type ?? NodeEntityType::DEPARTMENT,
				(bool)$node->globalActive,
				(int)$node->parentId > 0 ? (int)$node->parentId : null,
				count($chain) + 1,
				$path . '/' . (string)$node->name,
			);
		}

		return $rows;
	}

	/**
	 * Where the structure is given no ceiling of its own, nothing keeps the read down to the rows the walk
	 * may publish, so the picked list is cut here instead.
	 *
	 * @param list<int> $sourceNodeIds
	 * @return list<int>
	 */
	private function boundSources(array $sourceNodeIds, StructureSelection $selection): array
	{
		return $this->walkCeiling($selection) === null
			? array_slice($sourceNodeIds, 0, max(0, $selection->limit))
			: $sourceNodeIds
		;
	}

	/**
	 * Null where there is nothing to cut by. A ceiling is worth no more than the order it cuts in, and the
	 * order the structure answers in is the distance from the anchor of the walk: the root for All, the
	 * source nodes for Branch and Children. Element and Parent have no such distance.
	 */
	private function walkCeiling(StructureSelection $selection): ?int
	{
		$anchored = match ($selection->mode)
		{
			StructureSelectionMode::All,
			StructureSelectionMode::Branch,
			StructureSelectionMode::Children => true,
			StructureSelectionMode::Element, StructureSelectionMode::Parent => false,
		};

		return $anchored ? $selection->limit : null;
	}

	/**
	 * @param ?int $limit the ceiling of the read, asked of the structure itself and not of the answer
	 * @return array<int, Node> nodeId => node
	 */
	private function readNodes(NodeFilter $filter, ?int $limit = null): array
	{
		$builder = NodeDataBuilder::createWithFilter($filter)->setSelect(self::NODE_COLUMNS);
		if ($limit !== null && $limit > 0)
		{
			$builder->setLimit($limit)->setSort(new StructureColumnSort(['CHILD_NODES.DEPTH', 'ID']));
		}

		$byId = [];
		foreach ($builder->getAll() as $node)
		{
			if ($node->id !== null)
			{
				$byId[(int)$node->id] = $node;
			}
		}

		return $byId;
	}

	/**
	 * @param list<int> $nodeIds
	 * @return array<int, string>
	 */
	private function readNames(array $nodeIds): array
	{
		$names = [];
		$filter = new NodeFilter(idFilter: IdFilter::fromIds($nodeIds), active: NodeActiveFilter::ALL);
		foreach ($this->readNodes($filter) as $nodeId => $node)
		{
			$names[$nodeId] = (string)$node->name;
		}

		return $names;
	}

	/**
	 * @param list<int> $nodeIds
	 * @param int $limit the ceiling of the read, asked of the structure itself and not of the answer
	 * @return array<int, list<int>> nodeId => user ids holding the role there
	 */
	private function readRoleMembers(array $nodeIds, NodeMemberRole $role, int $limit): array
	{
		// One builder for every role of the read: its constructor lists the roles of the structure, and that
		// list is the same query however many times it is asked for.
		$this->memberBuilder ??= new NodeMemberDataBuilder();

		$members = $this->memberBuilder
			->setFilter(
				new NodeMemberFilter(
					entityType: MemberEntityType::USER,
					nodeFilter: new NodeFilter(idFilter: IdFilter::fromIds($nodeIds), active: NodeActiveFilter::ALL),
					active: true,
					roleFilter: RoleFilter::fromRole($role),
				),
			)
			->setLimit($limit)
			->setSort(new StructureColumnSort(['NODE_ID', 'ENTITY_ID']))
			->getAll()
		;

		$userIds = [];
		foreach ($members as $member)
		{
			$userIds[(int)$member->nodeId][] = (int)$member->entityId;
		}

		return $userIds;
	}

	/** A bare id, typed or computed by an expression, and the access codes of the structure. */
	private function toAccessCode(mixed $token): ?string
	{
		if (is_int($token))
		{
			return $token > 0 ? AccessCodeType::HrStructureNodeType->buildAccessCode($token) : null;
		}

		if (!is_string($token))
		{
			return null;
		}

		$token = trim($token);
		if (preg_match('/^\d+$/', $token) === 1)
		{
			return (int)$token > 0 ? AccessCodeType::HrStructureNodeType->buildAccessCode((int)$token) : null;
		}

		$token = mb_strtoupper($token);
		foreach (AccessCodeType::getAllStructurePrefixes() as $prefix)
		{
			if (preg_match('/^' . $prefix . '(\d+)$/', $token, $matches) === 1 && (int)$matches[1] > 0)
			{
				return $token;
			}
		}

		return null;
	}

	private function nodeIdOfAccessCode(string $accessCode): int
	{
		return (int)preg_replace('/^\D+/', '', $accessCode);
	}

	/**
	 * A code of a kind the structure does not know makes it refuse the whole set, and then nothing was
	 * picked at all.
	 *
	 * @param array<string, string> $accessCodes
	 * @return list<int>
	 */
	private function readNodeIdsOfCodes(array $accessCodes): array
	{
		if (!$accessCodes)
		{
			return [];
		}

		try
		{
			return $this->normalizeIds(Container::getNodeService()->getNodeIdsByAccessCodes(array_values($accessCodes)));
		}
		catch (\InvalidArgumentException)
		{
			return [];
		}
	}

	/**
	 * @param list<int> $nodeIds
	 * @param list<NodeEntityType> $nodeTypes
	 * @return list<int> the ids the structure holds of the kinds asked for, in the order they came in
	 */
	private function keepNodesOfTypes(array $nodeIds, array $nodeTypes): array
	{
		if (!$nodeIds)
		{
			return [];
		}

		$found = $this->readNodes(
			new NodeFilter(
				idFilter: IdFilter::fromIds($nodeIds),
				entityTypeFilter: NodeTypeFilter::fromNodeTypes($nodeTypes),
				active: NodeActiveFilter::ALL,
			),
		);

		return array_values(array_filter($nodeIds, static fn(int $nodeId): bool => isset($found[$nodeId])));
	}

	/**
	 * @param list<NodeMemberRole> $roles
	 * @return list<NodeMemberRole> without repeats, in the order of the dictionary
	 */
	private function orderRoles(array $roles): array
	{
		return array_values(
			array_filter(
				NodeMemberRole::cases(),
				static fn(NodeMemberRole $role): bool => in_array($role, $roles, true),
			),
		);
	}

	/**
	 * @return list<int>
	 */
	private function normalizeIds(array $ids): array
	{
		$normalized = [];
		foreach ($ids as $id)
		{
			if ((int)$id > 0)
			{
				$normalized[] = (int)$id;
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * @param list<NodeEntityType> $nodeTypes
	 */
	private static function typeKey(array $nodeTypes): string
	{
		$values = array_map(static fn(NodeEntityType $type): string => $type->value, $nodeTypes);
		sort($values);

		return implode(',', $values);
	}
}
