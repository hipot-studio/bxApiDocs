<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Check;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\Internal\Service\Sync\Position\CheckToken;
use Bitrix\Disk\Internal\Service\Sync\SyncLimits;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Security\DiskSecurityContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * What of an area a client names by its root the reader may still see, one page at a time.
 *
 * The other mode of the check is told which objects to answer about; this one finds them itself and
 * every object it finds is an object the reader sees, so a page of it is a page of visible verdicts
 * and of nothing else. What the two modes share is the shape of an answer about one object
 * ({@see CheckVerdict}) - one method serves both, and its items may not differ between them.
 *
 * The area is unfolded by the database and never by the application: the condition "a descendant of
 * the root" rides on an ordinary list query as a correlated EXISTS over the closure table
 * ({@see ObjectTable::addSubtreeCheckByExists}), covered by the unique key of that table. Unfolding
 * an area into a list of identifiers here would bring back exactly the cost a change of access is
 * published as one event about a subtree to avoid.
 *
 * Four properties of the page are deliberate:
 *
 *  - a root the reader does not see gives an empty finished page rather than a refusal. That is the
 *    ordinary case and not an edge one: the first move of a reconciliation runs right after a
 *    revocation, when the root is already invisible. A refusal would both tell the client that the
 *    root exists - against the invariant the whole check is written under - and force it to drive
 *    its normal path through an error. An empty page of an area that is empty, of an area whose root
 *    was never there and of an area whose root is hidden is one and the same page, and it stays one
 *    and the same page when the reader was given the reading of an object below that hidden root:
 *    the root is read under the same rights expression the area is filtered by, and what the area
 *    was not allowed to answer for is answered for by the second move of the reconciliation, the
 *    check over a list;
 *  - no total is counted. Under a correlated EXISTS a COUNT is a second full pass over the subtree,
 *    which is the whole saving of paging spent twice;
 *  - the trashcan is not filtered out. An object in the trashcan is still an object the reader sees
 *    (F19), and a filter on the type of deletion would have the check tell the client to forget it.
 *    This is why the query is built over the seam directly rather than through
 *    {@see \Bitrix\Disk\Folder::getDescendants()}, which adds that filter for a caller who did not;
 *  - links are collapsed the way the initial fetch collapses them. The cards of the contour name the
 *    real object, so a link inside the area is answered for by the object it points at, and two
 *    links to one object are one verdict. Collapsing works inside a page, as it does on the fetch:
 *    links of one object lying in different pages name it once each, which costs the client the same
 *    card twice and nothing else. Losing an object is what pages may never do; repeating one is not
 *    worth a memory of the whole traversal.
 *
 * The cost of the mode follows the size of the subtree rather than the number of objects the answer
 * holds: with a storage root as the root of the area the predicate runs over every object of that
 * storage. The exchange - one event per subtree against the price of checking it - is made
 * knowingly.
 *
 * The limit of the area is worth holding in mind while reading the reconciliation of a client: the
 * closure table describes the physical tree, and the subtree condition narrows the read by the
 * storage of the root, so an object the reader sees through a link that lives elsewhere is not in
 * the area at all. Those are the very objects that cannot be matched against a root, and the second
 * move of the reconciliation - the check over a list - is what exists for them. The server does not
 * try to gather them into the area; it hands over what the area holds.
 */
final class ScopeModeChecker
{
	/**
	 * The rights predicate is correlated and cannot be applied before the ordering, so the read is
	 * narrowed by the subtree first and only then filtered by rights - the way every large list of
	 * Disk works.
	 *
	 * It is written over the real object and not over the row that was found. What the answer names is
	 * the real object, and a link is an object of its own with rights of its own: read by the rights of
	 * the row, the check would call an object visible on the strength of a link to it and hand over the
	 * card of a file the reader may not open. For everything that is not a link the two are the same
	 * row, and the join is by the primary key.
	 */
	private const RIGHTS_COLUMNS = ['REAL_OBJECT.ID', 'REAL_OBJECT.CREATED_BY'];

	private SyncLimits $limits;

	public function __construct(?SyncLimits $limits = null)
	{
		$this->limits = $limits ?? new SyncLimits();
	}

	/**
	 * One page of the area of the token, and the token that continues it.
	 *
	 * The ceiling honoured here is the one a page is published with; weighing the sections a card is
	 * assembled for belongs to the method that has sections, and the other mode of the check is
	 * bounded by the same ceiling for the same reason.
	 *
	 * A page may be shorter than the limit when links collapsed inside it. Whether the area goes on
	 * is answered by hasMore and never by the size of a page.
	 *
	 * @return array{verdicts: CheckVerdict[], hasMore: bool, token: CheckToken}
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function check(CheckToken $token, int $limit): array
	{
		$limit = max(1, min($limit, $this->limits->getPageSizeMax()));

		// The rights expression holds a branch for everything published to authorised users, and that
		// branch does not depend on who is asking: without a reader there is nothing to answer to.
		if ($token->getUserId() <= 0)
		{
			return self::emptyPage($token);
		}

		$storageId = self::storageOfRootTheReaderSees($token->getScopeRootId(), $token->getUserId());
		if ($storageId === null)
		{
			return self::emptyPage($token);
		}

		$rows = $this->readRows($token, $storageId, $limit + 1);
		if ($rows === [])
		{
			return self::emptyPage($token);
		}

		$hasMore = count($rows) > $limit;
		$rows = array_slice($rows, 0, $limit);
		$boundary = $rows[array_key_last($rows)];

		return [
			'verdicts' => self::verdictsOf($rows),
			'hasMore' => $hasMore,
			'token' => $token->withPosition($boundary['freshnessAt'], $boundary['objectId']),
		];
	}

	/**
	 * One ordered read of the subtree, bounded from above by the boundary of state the token carries.
	 *
	 * The boundary is what makes a page repeatable: the sort key mutates, so without it an object
	 * changed between two pages would move across pages already read and be lost from the traversal.
	 * What left the traversal that way did so by changing, and a change is what the journal carries.
	 *
	 * @return array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}>
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private function readRows(CheckToken $token, int $storageId, int $take): array
	{
		$rootId = $token->getScopeRootId();
		$byExists = Configuration::isEnabledSubtreeCheckByExists();

		$filter = [];
		if ($byExists)
		{
			// the storage of a descendant always matches the storage of its container, so the condition
			// narrows candidates by an index of the object table before the subtree is checked
			$filter['=STORAGE_ID'] = $storageId;
		}
		$filter['<=UPDATE_TIME'] = self::upperBoundOf($token);
		self::applyKeyset($filter, $token->getLastFreshnessAt(), $token->getLastObjectId());

		$parameters = [
			'select' => ['ID', 'REAL_OBJECT_ID', 'UPDATE_TIME'],
			'filter' => $filter,
			'order' => ['UPDATE_TIME' => 'DESC', 'ID' => 'DESC'],
			'limit' => $take,
			'subtree_check' => $byExists ? ObjectTable::SUBTREE_CHECK_EXISTS : ObjectTable::SUBTREE_CHECK_JOIN,
		];

		if ($byExists)
		{
			// the cheap subtree condition is written ahead of the expensive rights check below: the
			// database promises no order of evaluation, but the plans seen here keep the one written
			$parameters = ObjectTable::addSubtreeCheckByExists($parameters, $rootId);
		}

		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($token->getUserId()),
			$parameters,
			self::RIGHTS_COLUMNS,
		);

		$rows = [];
		$cursor = ObjectTable::getDescendants($rootId, $parameters);
		while ($row = $cursor->fetch())
		{
			$rows[] = [
				'objectId' => (int)$row['ID'],
				'realObjectId' => (int)$row['REAL_OBJECT_ID'],
				'freshnessAt' => $row['UPDATE_TIME'],
			];
		}

		return $rows;
	}

	/**
	 * Keyset by the pair "freshness, identifier", never an offset: an offset re-reads and throws away
	 * everything before it, and over a subtree walked in dozens of pages that is the whole cost.
	 *
	 * The range on the key column is kept as a range so that the index still serves the ordering; the
	 * tie on the identifier is a residual condition over the few rows sharing a second. Since the
	 * position of a page is taken from the last row actually handed over, the pair moves strictly
	 * downwards and the pages neither repeat a row nor step over one.
	 *
	 * @param array<int|string, mixed> $filter
	 */
	private static function applyKeyset(array &$filter, ?DateTime $afterFreshnessAt, ?int $afterObjectId): void
	{
		if ($afterFreshnessAt === null || $afterObjectId === null)
		{
			return;
		}

		$filter[] = [
			'LOGIC' => 'OR',
			'<UPDATE_TIME' => $afterFreshnessAt,
			'<ID' => $afterObjectId,
		];
	}

	/**
	 * The window of the read is closed by whichever is lower - the boundary of state or the freshness
	 * the previous page stopped at. One key of a filter written as an array holds one condition, and
	 * the lower of the two implies the other.
	 */
	private static function upperBoundOf(CheckToken $token): DateTime
	{
		$boundAt = $token->getBoundAt();
		$lastFreshnessAt = $token->getLastFreshnessAt();

		return $lastFreshnessAt !== null && $lastFreshnessAt->getTimestamp() < $boundAt->getTimestamp()
			? $lastFreshnessAt
			: $boundAt
		;
	}

	/**
	 * The storage the area lies in, or null for a root the reader is owed no answer about.
	 *
	 * One read settles both questions a root is asked: where the area lies and whether the reader sees
	 * the root of it. The second is asked with the very expression the objects of the area are filtered
	 * by, so the gate on the root and the filter below it cannot drift apart. A root that is not there
	 * and a root the reader may not see leave by one and the same path, and neither the row nor the
	 * fact that it was found reaches the answer.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	private static function storageOfRootTheReaderSees(int $rootId, int $userId): ?int
	{
		$parameters = Driver::getInstance()->getRightsManager()->addRightsCheck(
			new DiskSecurityContext($userId),
			[
				'select' => ['STORAGE_ID'],
				'filter' => ['=ID' => $rootId],
			],
			self::RIGHTS_COLUMNS,
		);

		$row = ObjectTable::getRow($parameters);

		return $row === null ? null : (int)$row['STORAGE_ID'];
	}

	/**
	 * @param array<int, array{objectId: int, realObjectId: int, freshnessAt: DateTime}> $rows
	 * @return CheckVerdict[]
	 */
	private static function verdictsOf(array $rows): array
	{
		$verdicts = [];
		$collapsed = [];
		foreach ($rows as $row)
		{
			$realObjectId = $row['realObjectId'];
			if (isset($collapsed[$realObjectId]))
			{
				continue;
			}

			$collapsed[$realObjectId] = true;
			$verdicts[] = CheckVerdict::visible($realObjectId);
		}

		return $verdicts;
	}

	/**
	 * The position stays where it was: a page that handed nothing over has nothing to move it past.
	 *
	 * @return array{verdicts: CheckVerdict[], hasMore: bool, token: CheckToken}
	 */
	private static function emptyPage(CheckToken $token): array
	{
		return [
			'verdicts' => [],
			'hasMore' => false,
			'token' => $token,
		];
	}
}
