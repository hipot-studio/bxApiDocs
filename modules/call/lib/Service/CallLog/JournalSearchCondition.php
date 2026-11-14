<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

/**
 * The search of the journal as a condition of the query of the page: the branches of the two sources joined by OR.
 *
 * Neither source answers with rows. Each answers with a branch of the condition, and the query of the page carries
 * them next to the owner, the filter, the cursor, the order and the limit, so a page is cut by the search and by all
 * of those at once and no list of ids passes through PHP on the way.
 *
 * The branch of the first source asks the columns of the search table, so the table is joined under the name that
 * branch asks it by ({@see JournalIndexSearch::INDEX_REFERENCE}), and how depends on what stands beside it:
 *
 * - both branches - a left join: a row the search table does not hold may still be found through the people of its
 *   call, and an inner join would drop it before OR is asked;
 * - the branch of the index alone - an inner join: no row without an index row can answer, and the join is left
 *   free to be led by the fulltext pass;
 * - the branch of the participants alone - no join at all.
 *
 * The fulltext match stands in the outer query only: the subqueries of the branch of the participants carry none,
 * and under OR they are not flattened into a semi-join - see ParticipantSearch for the rows MySQL loses there.
 */
final class JournalSearchCondition
{
	private function __construct(
		private readonly ?ConditionTree $byIndex,
		private readonly ?ConditionTree $byParticipants,
	)
	{
	}

	/**
	 * @param ConditionTree|null $byIndex The branch of {@see JournalIndexSearch::conditionOf()}.
	 * @param ConditionTree|null $byParticipants The branch of {@see ParticipantSearch::conditionOf()}.
	 * @return self|null Null when neither source has a branch: the search answers nothing before any page is asked.
	 */
	public static function anyOf(?ConditionTree $byIndex, ?ConditionTree $byParticipants): ?self
	{
		if ($byIndex === null && $byParticipants === null)
		{
			return null;
		}

		return new self($byIndex, $byParticipants);
	}

	/**
	 * Puts the search into a query over the journal, CallUserLogTable.
	 *
	 * The branches are handed over as copies: a condition is remembered for the lifetime of the service and put into
	 * the query of every read of a page, and the query that compiles a condition may rewrite it in place.
	 */
	public function applyTo(Query $query): void
	{
		if ($this->byIndex !== null)
		{
			$joinType = $this->byParticipants === null ? Join::TYPE_INNER : Join::TYPE_LEFT;
			$query->registerRuntimeField(
				JournalIndexSearch::INDEX_REFERENCE,
				JournalIndexSearch::referenceToTheIndex($joinType),
			);
		}

		$anyOf = Query::filter()->logic(ConditionTree::LOGIC_OR);
		foreach ([$this->byIndex, $this->byParticipants] as $branch)
		{
			if ($branch !== null)
			{
				$anyOf->where(clone $branch);
			}
		}

		$query->where($anyOf);
	}
}
