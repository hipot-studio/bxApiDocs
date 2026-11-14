<?php

declare(strict_types=1);

namespace Bitrix\Call\Service\CallLog;

use Bitrix\Main\ORM\Query\Query;

/**
 * The filter of a page of the journal: the conditions of the request - statuses, side, exclusion, cursor - as an
 * array filter over CallUserLogTable, and the search as a condition of its own.
 *
 * Two parts because they reach the query in two forms. The array goes to setFilter(), as every consumer of the
 * journal has always handed it; the search needs a join and a tree of conditions, which an array filter cannot carry
 * - its key of a fulltext match wraps the value into an expression of its own ({@see \CSQLWhere::match()}), and a
 * join is no part of it at all.
 */
final class JournalQueryFilter
{
	/**
	 * @param array $conditions Filter over CallUserLogTable.
	 * @param JournalSearchCondition|null $search Null when the request does not search.
	 */
	public function __construct(
		private readonly array $conditions,
		private readonly ?JournalSearchCondition $search = null,
	)
	{
	}

	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function getSearch(): ?JournalSearchCondition
	{
		return $this->search;
	}

	/**
	 * Puts the filter into a query over the journal.
	 *
	 * The conditions go in as one group of their own. The array filter and the conditions of where() are joined by
	 * AND with no brackets around the array (Query::buildWhere()), so a LOGIC => OR at the top of the filter would
	 * otherwise bind the conditions set by where() - the owner, the search - to its last branch only.
	 */
	public function applyTo(Query $query): void
	{
		$query->setFilter([$this->conditions]);
		$this->search?->applyTo($query);
	}
}
