<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Call\Model\CallUserLogTable;
use Bitrix\Call\Model\CallUserTable;
use Bitrix\Main\Loader;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\UserIndexTable;
use Bitrix\Main\UserTable;

/**
 * The second source of the search of the journal: which rows of the owner belong to calls a person of
 * this name took part in, and whose roster the owner may read.
 *
 * The walk is split in steps, and the first one - a fulltext match against the user index of the core -
 * runs as a query of its own, ahead of the rest. Handed on as a subquery instead, it is flattened into one
 * semi-join with the roster and the journal, and when the optimiser puts the fulltext table inside the
 * nested loop rather than in front of it, MySQL (8.4) loses rows: a call of the owner comes back on some
 * passes of the loop and not on others. A list of ids carries no fulltext into the second query at all.
 *
 * The split is also what makes the path testable: inside a transaction InnoDB does not refresh a fulltext
 * index before the commit, so a person seeded by a case is not found by their own name. Every invariant of
 * the path but the limit of the first step sits on the steps after it, which hold no fulltext index and do
 * see seeded rows; the limit is reached through self::matchedUserIds().
 *
 * The answer follows the rule of the roster: a row found through the people of a call goes only to an
 * owner who is a member of the chat of that call right now - see CallChatMembership. Without it a person
 * taken out of the chat would learn who was in the call one name per query, though the list no longer
 * draws them its roster.
 *
 * The expression is not built here. The service builds one for the query of the user and hands the same
 * one to both of its sources.
 *
 * What the user index is searched by is not ours to narrow: UserTable::generateSearchUserContent() folds
 * NAME, LAST_NAME and WORK_POSITION into one text. Hence a first name and a last name are found, a middle
 * name is not there at all, and a word of a job position matches along the way.
 */
class ParticipantSearch
{
	/**
	 * The most people a name may match across the portal; past it the name is looked for among the companions
	 * of the owner alone - see self::peopleOf().
	 */
	public const MATCHED_USERS_LIMIT = 500;

	/**
	 * The people of a name, by owner and then by expression, for the lifetime of this source: one page asks the same
	 * search for every read of the journal, and the directory is a query of its own.
	 *
	 * @var array<int, array<string, int[]>>
	 */
	private array $matchedPeople = [];

	/**
	 * The branch of the condition of the page this source answers for: rows of calls a person of the name joined,
	 * of chats the owner is a member of right now.
	 *
	 * The people are a list of ids read ahead by a query of their own, and only the steps after the directory are
	 * conditions of the query of the page: the calls those people joined and the calls of the chats of the owner,
	 * each a subquery with no fulltext index in it. The fulltext match of the directory stays out of that query for
	 * the reason the description of the class gives.
	 *
	 * Asked against the journal as the query of the page reads it: the owner of the rows is a condition of that
	 * query, so neither source can answer with a row of somebody else. SOURCE_TYPE is a matter of correctness: an
	 * identifier of a source is unique only within its own type, and without it rows of telephony whose identifier
	 * collides with a call id would come back as calls.
	 *
	 * The price is the directory alone - one query per owner and expression for the lifetime of this source, up to
	 * three past self::MATCHED_USERS_LIMIT - whatever number of calls is found and however many pages are read.
	 *
	 * @return ConditionTree|null Null when there is no branch: nobody carries the name, or there are no chats to be
	 *     a member of - a portal without module im.
	 */
	public function conditionOf(string $expression, int $ownerId): ?ConditionTree
	{
		if (!Loader::includeModule('im'))
		{
			return null;
		}

		$userIds = $this->peopleOf($expression, $ownerId);
		if ($userIds === [])
		{
			return null;
		}

		return Query::filter()
			->where('SOURCE_TYPE', CallUserLogTable::SOURCE_TYPE_CALL)
			->whereIn('SOURCE_CALL_ID', $this->callsJoinedByQuery($userIds))
			->whereIn('SOURCE_CALL_ID', CallChatMembership::callsOfMemberQuery($ownerId))
		;
	}

	/**
	 * Who of the real users of the portal, the owner aside, carries this name.
	 *
	 * Returned unexecuted, so that a caller may narrow it to one account. Asked for one person past
	 * self::MATCHED_USERS_LIMIT: that one is how a name too wide for the portal is told from a name that
	 * matches exactly as many.
	 *
	 * The owner is left out by the query itself, ahead of the limit. They took part in nearly every call of
	 * their own journal, so their own name would answer with nearly all of it, and they would take a place
	 * under the limit from somebody they may actually be looking for.
	 *
	 * ACTIVE is deliberately not asked for: a call with a colleague who has left is a sensible thing to
	 * look for, and the roster of the row draws them.
	 */
	public function matchedUsersQuery(string $expression, int $ownerId): Query
	{
		return $this->directoryQuery($expression, $ownerId)
			->setLimit(self::MATCHED_USERS_LIMIT + 1)
		;
	}

	/**
	 * Everybody who joined a call of the journal of the owner, the owner aside, once each.
	 *
	 * No fulltext index takes part, so the calls of the owner may come as a subquery here. Bounded by the
	 * journal of the owner, which is kept for the term of storage only.
	 *
	 * Returned unexecuted so that a test can read its shape.
	 */
	public function companionsQuery(int $ownerId): Query
	{
		$callsOfTheOwner = CallUserLogTable::query()
			->setSelect(['SOURCE_CALL_ID'])
			->where('USER_ID', $ownerId)
			->where('SOURCE_TYPE', CallUserLogTable::SOURCE_TYPE_CALL)
		;

		return CallUserTable::query()
			->setDistinct()
			->setSelect(['USER_ID'])
			->whereIn('CALL_ID', $callsOfTheOwner)
			->whereNotNull('FIRST_JOINED')
			->whereNot('USER_ID', $ownerId)
		;
	}

	/**
	 * Who of these companions of the owner carries this name.
	 *
	 * The companions come as a list of ids, not as self::companionsQuery(): a subquery next to a fulltext match
	 * is a semi-join with a fulltext table in it, the shape MySQL (8.4) loses rows in - see the description of
	 * the class. No limit: the list is bounded by the journal of the owner, and any one of them may be the
	 * person looked for.
	 *
	 * Returned unexecuted so that a test can read its shape.
	 *
	 * @param int[] $companionIds Not empty: an empty list would take the condition off the query altogether.
	 */
	public function matchedCompanionsQuery(string $expression, int $ownerId, array $companionIds): Query
	{
		return $this->directoryQuery($expression, $ownerId)
			->whereIn('USER_ID', $companionIds)
		;
	}

	/**
	 * The people of the name, the owner aside: the directory up to the limit, the companions of the owner past it.
	 *
	 * The directory answers in no particular order, so the first of more people than the limit would make which
	 * calls come back a matter of chance; and the call of a participant with a common name must not drop out, since
	 * the other source does not know the names of participants. Past the limit the name is looked for among the
	 * companions of the owner instead: a row of the owner can only be found through somebody who joined that very
	 * call, so the answer is the same and only the list is bounded by the journal of the owner.
	 *
	 * @return int[]
	 */
	private function peopleOf(string $expression, int $ownerId): array
	{
		if (!isset($this->matchedPeople[$ownerId][$expression]))
		{
			$userIds = $this->matchedUserIds($expression, $ownerId);
			if (count($userIds) > self::MATCHED_USERS_LIMIT)
			{
				$userIds = $this->matchedCompanionIds($expression, $ownerId);
			}

			$this->matchedPeople[$ownerId][$expression] = $userIds;
		}

		return $this->matchedPeople[$ownerId][$expression];
	}

	/**
	 * The calls these people joined.
	 *
	 * A participant is whoever joined: a person who was invited and never connected has a row of membership all the
	 * same, and the roster that draws the faces of the row reads it by this very predicate - the search must not
	 * disagree with what the list shows. The calls of the people are found by IX_CALL_USER_USER_CALL; IN does not
	 * multiply the rows of the journal, so nothing has to be grouped.
	 *
	 * @param int[] $userIds Not empty: an empty list would take the condition off the query altogether.
	 */
	private function callsJoinedByQuery(array $userIds): Query
	{
		return CallUserTable::query()
			->setSelect(['CALL_ID'])
			->whereIn('USER_ID', $userIds)
			->whereNotNull('FIRST_JOINED')
		;
	}

	/**
	 * Protected because the directory is a fulltext index: a person seeded by a test is not in it before the
	 * commit, so the limit cannot be reached by seeding people.
	 *
	 * @internal Widened for the tests of the search and for nothing else. This is no point of extension.
	 * @return int[]
	 */
	protected function matchedUserIds(string $expression, int $ownerId): array
	{
		return $this->userIdsOf($this->matchedUsersQuery($expression, $ownerId));
	}

	/**
	 * @return int[]
	 */
	private function matchedCompanionIds(string $expression, int $ownerId): array
	{
		$companionIds = $this->userIdsOf($this->companionsQuery($ownerId));
		if ($companionIds === [])
		{
			return [];
		}

		return $this->userIdsOf($this->matchedCompanionsQuery($expression, $ownerId, $companionIds));
	}

	/**
	 * The directory as both ways of asking it share it: real users, the owner aside, of this name.
	 */
	private function directoryQuery(string $expression, int $ownerId): Query
	{
		return UserIndexTable::query()
			->setSelect(['USER_ID'])
			->registerRuntimeField(
				'USER',
				(new Reference(
					'USER',
					UserTable::class,
					Join::on('this.USER_ID', 'ref.ID'),
				))->configureJoinType(Join::TYPE_INNER),
			)
			->where('USER.IS_REAL_USER', 'Y')
			->whereNot('USER_ID', $ownerId)
			->whereMatch('SEARCH_USER_CONTENT', $expression)
		;
	}

	/**
	 * @return int[]
	 */
	private function userIdsOf(Query $query): array
	{
		return array_map(
			static fn (array $row): int => (int)$row['USER_ID'],
			$query->fetchAll(),
		);
	}
}
