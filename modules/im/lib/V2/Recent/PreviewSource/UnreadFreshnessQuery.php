<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Recent\PreviewSource;

use Bitrix\Im\Model\MessageUnreadTable;
use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\Tree\ChatAncestorNavigator;
use Bitrix\Im\V2\Chat\Tree\TreeLevel;
use Bitrix\Im\V2\Chat\Tree\TreeOrigin;
use Bitrix\Main\Application;
use Bitrix\Main\DB\MysqlCommonConnection;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * For the accessible candidates of a collab (the collab main chat + its direct children), returns the
 * freshest unread MESSAGE_ID (MAX(MESSAGE_ID) as a monotone-in-time surrogate) in each candidate's
 * subtree, grouped by the candidate ("root" of its bucket).
 *
 * On MySQL every path (the single-user read path and the member fan-out) goes through the flat
 * per-leaf statement ({@see fetchForMembers}): it is driven by the constant USER_ID equality on
 * unread - an estimate the optimizer resolves by index dive, immune to per-key statistics - and
 * every other access is an equality probe along the ancestor chain. Its cost is O(user's unread)
 * regardless of the collab's size, and no plan can multiply two independent cardinalities.
 *
 * Any form that iterates candidates in SQL is forbidden here. A candidate-anchored variant with
 * correlated freshness subqueries used to live in this class and melted production shards: for a
 * DEPENDENT SUBQUERY the correlated value is unknown at plan time, no index dive is possible, and
 * the skewed chat.PARENT_ID per-key averages made the optimizer re-drive each subquery from the
 * user's unread rows - O(candidates x user's unread), minutes on collabs with thousands of
 * task-chat candidates.
 *
 * Other dialects keep one aggregating ORM query per depth with id lists: on PostgreSQL id lists are
 * planned via =ANY() without a dive limit and measured strictly faster, so that path must not be
 * "unified" into the MySQL one.
 */
class UnreadFreshnessQuery
{
	public function __construct(
		private readonly ChatAncestorNavigator $navigator,
		private readonly int $maxDepth = ChatAncestorNavigator::DEFAULT_DEPTH,
	) {}

	/**
	 * @param int[] $candidateIds accessible candidates of the collab (the collab main chat itself +
	 *        its direct children, see CollabPreviewSourceResolver)
	 * @return array<int,int> map candidate chat id => freshest unread MESSAGE_ID of its bucket
	 */
	public function fetch(int $collabChatId, array $candidateIds, int $userId): array
	{
		if (empty($candidateIds))
		{
			return [];
		}

		if ($this->isFlatSingleUserFetchPreferred())
		{
			return $this->fetchForMembers([$userId => $candidateIds], [$collabChatId])[$userId] ?? [];
		}

		return $this->fetchByCandidateIds($candidateIds, $userId);
	}

	/**
	 * Multi-user freshness for the fan-out path: one flat statement serves a whole member chunk.
	 *
	 * Per unread leaf the statement fetches the ancestor id chain (levels 0..maxDepth) and a per-level
	 * accessibility flag; the nearest-candidate assignment runs in PHP over each member's own candidate
	 * set, so members with different mute/hidden states share the statement. The statement is scoped
	 * server-side by the parent collab ids: every candidate is either a collab itself or its direct
	 * child, so a leaf can only be assigned when a scope chat appears in its ancestor chain at depth
	 * <= maxDepth + 1. That superset predicate keeps the transported rows proportional to the members'
	 * unread inside the collab subtrees; the PHP assignment below stays authoritative.
	 *
	 * The statement's cost grows with a member's portal-wide unread and with nothing else: tens of
	 * milliseconds even for a member with tens of thousands of unread rows, flat in the collab's
	 * size. The fan-out amortizes it into one statement per member chunk; the single-user read path
	 * ({@see fetch}) runs it for one user. The caller bounds the chunk
	 * ({@see CollabPreviewSourceResolver::resolveForMembers}).
	 *
	 * Equivalence to the single-user forms: a leaf counts toward candidate R at depth d iff R is its
	 * closest candidate ancestor and every level 1..d is accessible - exactly the nearest-bucket rule
	 * of the per-depth queries and the anchored SQL, evaluated per member in {@see assignNearestCandidate}.
	 *
	 * @param array<int,int[]> $candidatesByUser userId => accessible candidate chat ids of that user
	 * @param int[] $scopeChatIds parent collab ids the candidates belong to
	 * @return array<int,array<int,int>> userId => (candidate chat id => freshest unread MESSAGE_ID)
	 */
	public function fetchForMembers(array $candidatesByUser, array $scopeChatIds): array
	{
		$scopeChatIds = array_values(array_unique(array_map('intval', $scopeChatIds)));
		if (empty($scopeChatIds))
		{
			throw new \LogicException('The fan-out freshness statement must be scoped by the parent collab ids');
		}

		$candidateSet = [];
		$internedSets = [];
		foreach ($candidatesByUser as $userId => $candidateIds)
		{
			$userId = (int)$userId;
			if ($userId <= 0 || empty($candidateIds))
			{
				// A member without accessible candidates can never receive an assignment - keep them
				// out of the statement entirely (mirrors the single-user empty-input short-circuit).
				continue;
			}

			$candidateIds = array_map('intval', $candidateIds);
			sort($candidateIds);
			// Members of one collab mostly share an identical candidate list - intern the hash sets;
			// sorting makes the key order-insensitive, so equal sets always share one entry.
			$internKey = implode(',', $candidateIds);
			$candidateSet[$userId] = $internedSets[$internKey] ??= array_fill_keys($candidateIds, true);
		}

		if (empty($candidateSet))
		{
			return [];
		}

		[$query, $levels] = $this->buildMemberChainQuery(array_keys($candidateSet), $scopeChatIds);

		// Stream: the loop only needs a running MAX per (user, candidate), so rows are consumed one at
		// a time instead of materializing the whole set with fetchAll() - bounds PHP peak memory when a
		// chunk's collab-subtree unread reaches hundreds of thousands of rows.
		$result = [];
		$cursor = $query->exec();
		while ($row = $cursor->fetch())
		{
			$userId = (int)$row['USER_ID'];
			$messageId = (int)$row['MESSAGE_ID'];
			if ($userId <= 0 || $messageId <= 0)
			{
				continue;
			}

			$rootChatId = $this->assignNearestCandidate($row, $levels, $candidateSet[$userId] ?? []);
			if ($rootChatId === null)
			{
				continue;
			}

			if (!isset($result[$userId][$rootChatId]) || $messageId > $result[$userId][$rootChatId])
			{
				$result[$userId][$rootChatId] = $messageId;
			}
		}

		return $result;
	}

	/**
	 * MySQL prefers the flat statement for a single user: driven by the constant USER_ID equality,
	 * its estimates never depend on per-key averages, so the plan survives any statistics skew.
	 * The per-depth queries stay as the other dialects' path (and the test oracle).
	 */
	protected function isFlatSingleUserFetchPreferred(): bool
	{
		return Application::getConnection() instanceof MysqlCommonConnection;
	}

	/**
	 * One aggregating ORM query per depth (0..maxDepth) grouped by a concrete root column, merged in
	 * PHP: a leaf at true depth d only has its direct-child ancestor at level d, avoiding a computed
	 * GROUP BY key.
	 *
	 * @param int[] $directChatIds
	 * @return array<int,int>
	 */
	private function fetchByCandidateIds(array $directChatIds, int $userId): array
	{
		$directChatIds = array_values(array_unique(array_map('intval', $directChatIds)));
		if (empty($directChatIds))
		{
			return [];
		}

		$result = [];
		for ($depth = 0; $depth <= $this->maxDepth; $depth++)
		{
			foreach ($this->fetchForDepth($directChatIds, $userId, $depth) as $rootChatId => $freshUnreadId)
			{
				if (!isset($result[$rootChatId]) || $freshUnreadId > $result[$rootChatId])
				{
					$result[$rootChatId] = $freshUnreadId;
				}
			}
		}

		return $result;
	}

	/**
	 * @param int[] $directChatIds
	 * @return array<int,int>
	 */
	private function fetchForDepth(array $directChatIds, int $userId, int $depth): array
	{
		$query = MessageUnreadTable::query()
			->where('USER_ID', $userId)
			->where('IS_MUTED', 'N')
		;

		$levels = $this->navigator->joinAncestors(
			$query,
			origin: TreeOrigin::forFields(id: 'CHAT_ID', parent: 'PARENT_ID'),
			depth: $depth,
			joinType: Join::TYPE_INNER,
		);

		$this->requireAccessibleRelation($query, 'LEAF_REL', 'CHAT_ID', $userId);

		$rootLevel = end($levels);

		if ($depth > 0)
		{
			$query->where($rootLevel->idExpression, '>', 0);
			$this->requireAccessibleAncestors($query, array_slice($levels, 1), $userId);
		}

		// Nearest-bucket assignment: a leaf is owned by its closest candidate ancestor only, so the
		// root candidate must be in the set while every closer level (the leaf and intermediate
		// ancestors) must not, otherwise that closer candidate already owns the leaf at a smaller depth.
		$query->whereIn($rootLevel->idExpression, $directChatIds);
		foreach ($levels as $level)
		{
			if ($level->depth < $depth)
			{
				$query->whereNotIn($level->idExpression, $directChatIds);
			}
		}

		$query
			->registerRuntimeField(
				'ROOT_CHAT_ID',
				new ExpressionField('ROOT_CHAT_ID', '%s', [$rootLevel->idExpression]),
			)
			->setSelect([
				'ROOT_CHAT_ID',
				'FRESH_UNREAD_ID' => new ExpressionField('FRESH_UNREAD_ID', 'MAX(%s)', ['MESSAGE_ID']),
			])
			->setGroup(['ROOT_CHAT_ID'])
		;

		$map = [];
		foreach ($query->fetchAll() as $row)
		{
			$rootChatId = (int)$row['ROOT_CHAT_ID'];
			$freshUnreadId = (int)$row['FRESH_UNREAD_ID'];
			if ($rootChatId > 0 && $freshUnreadId > 0)
			{
				$map[$rootChatId] = $freshUnreadId;
			}
		}

		return $map;
	}

	/**
	 * INNER-joins the user's relation to a chat and requires it to be neither muted nor hidden.
	 */
	private function requireAccessibleRelation(
		Query $query,
		string $alias,
		string $chatIdExpression,
		int $userId,
	): void
	{
		$query->registerRuntimeField(
			$alias,
			(new Reference(
				$alias,
				RelationTable::class,
				Join::on('ref.CHAT_ID', "this.{$chatIdExpression}")
					->where('ref.USER_ID', $userId),
			))->configureJoinType(Join::TYPE_INNER),
		);
		$query->where("{$alias}.NOTIFY_BLOCK", 'N');
		$query->where("{$alias}.IS_HIDDEN", 'N');
	}

	/**
	 * @param TreeLevel[] $ancestorLevels ancestor levels from the leaf's parent up to and including the root candidate
	 */
	private function requireAccessibleAncestors(Query $query, array $ancestorLevels, int $userId): void
	{
		foreach ($ancestorLevels as $level)
		{
			$this->requireAccessibleRelation(
				$query,
				"PREVIEW_ANC_REL_{$userId}_{$level->depth}",
				$level->idExpression,
				$userId,
			);
		}
	}

	/**
	 * Builds the flat per-leaf statement of the fan-out path.
	 *
	 * @param int[] $userIds
	 * @param int[] $scopeChatIds
	 * @return array{0: Query, 1: TreeLevel[]} the statement and the bucket levels (0..maxDepth)
	 */
	private function buildMemberChainQuery(array $userIds, array $scopeChatIds): array
	{
		$query = MessageUnreadTable::query()
			->whereIn('USER_ID', $userIds)
			->where('IS_MUTED', 'N')
		;

		// One extra ancestor level beyond the buckets: a candidate at depth d (0..maxDepth) is the
		// scope collab itself or its direct child, so the scope chat sits at depth <= maxDepth + 1.
		// LEFT so a leaf with a shallow tree (no grandparent) is preserved, not dropped.
		$levels = $this->navigator->joinAncestors(
			$query,
			origin: TreeOrigin::forFields(id: 'CHAT_ID', parent: 'PARENT_ID'),
			depth: $this->maxDepth + 1,
			joinType: Join::TYPE_LEFT,
		);

		// Superset restriction: only leaves with a scope chat somewhere in the joined chain can be
		// assigned to a candidate. NULL and 0 levels of shallow chains never match the IN.
		$scopeFilter = Query::filter()->logic('or');
		foreach ($levels as $level)
		{
			$scopeFilter->whereIn($level->idExpression, $scopeChatIds);
		}
		$query->where($scopeFilter);

		// The extra level exists only for the scope predicate; buckets stay 0..maxDepth.
		$levels = array_slice($levels, 0, $this->maxDepth + 1, true);

		// Leaf accessibility is always required (mirrors the single-user forms' INNER leaf relation).
		$this->joinMemberRelationProbe($query, 'LEAF_REL', $levels[0]->idExpression, Join::TYPE_INNER);

		$select = [
			'USER_ID',
			'MESSAGE_ID',
			'L_0' => new ExpressionField('L_0', '%s', [$levels[0]->idExpression]),
		];

		foreach ($levels as $level)
		{
			if ($level->depth === 0)
			{
				continue;
			}

			// LEFT join so the row survives an inaccessible ancestor; the flag records accessibility.
			$relAlias = "FANOUT_ANC_REL_{$level->depth}";
			$this->joinMemberRelationProbe($query, $relAlias, $level->idExpression, Join::TYPE_LEFT);

			$select["L_{$level->depth}"] = new ExpressionField("L_{$level->depth}", '%s', [$level->idExpression]);
			$select["ACC_{$level->depth}"] = new ExpressionField(
				"ACC_{$level->depth}",
				'CASE WHEN %s IS NULL THEN 0 ELSE 1 END',
				["{$relAlias}.ID"],
			);
		}

		$query->setSelect($select);

		return [$query, $levels];
	}

	/**
	 * Assigns a leaf to its closest candidate ancestor, then keeps it only if every level up to and
	 * including that ancestor is accessible. Returns the candidate chat id, or null when the leaf has
	 * no candidate ancestor or a level below the candidate is inaccessible.
	 *
	 * @param array<string,mixed> $row
	 * @param TreeLevel[] $levels
	 * @param array<int,true> $candidateSet
	 */
	private function assignNearestCandidate(array $row, array $levels, array $candidateSet): ?int
	{
		$rootDepth = null;
		foreach ($levels as $level)
		{
			$chatId = (int)($row["L_{$level->depth}"] ?? 0);
			if ($chatId > 0 && isset($candidateSet[$chatId]))
			{
				$rootDepth = $level->depth;

				break;
			}
		}

		if ($rootDepth === null)
		{
			return null;
		}

		for ($depth = 1; $depth <= $rootDepth; $depth++)
		{
			if ((int)($row["ACC_{$depth}"] ?? 0) !== 1)
			{
				return null;
			}
		}

		return (int)$row["L_{$rootDepth}"];
	}

	/**
	 * Joins the user's relation to a chat, requiring it to be neither muted nor hidden. Unlike
	 * {@see requireAccessibleRelation}, the user match and the mute/hidden guards live in the JOIN
	 * condition (not WHERE) so a LEFT join can serve as a nullable accessibility probe, and USER_ID
	 * is matched to the driving unread row so one statement serves all members.
	 */
	private function joinMemberRelationProbe(
		Query $query,
		string $alias,
		string $chatIdExpression,
		string $joinType,
	): void
	{
		$query->registerRuntimeField(
			$alias,
			(new Reference(
				$alias,
				RelationTable::class,
				Join::on('ref.CHAT_ID', "this.{$chatIdExpression}")
					->whereColumn('ref.USER_ID', 'this.USER_ID')
					->where('ref.NOTIFY_BLOCK', 'N')
					->where('ref.IS_HIDDEN', 'N'),
			))->configureJoinType($joinType),
		);
	}
}
