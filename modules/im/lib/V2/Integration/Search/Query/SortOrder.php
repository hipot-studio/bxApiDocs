<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Query;

/**
 * Sort orders the facade offers. The order is always stated explicitly: the default of the search
 * engine starts with custom_rank, which the messenger does not fill.
 */
enum SortOrder: string
{
	case Relevance = 'relevance';
	case Date = 'date';

	/**
	 * @return array<string, string> Sort array of CSearch::Search().
	 */
	public function toSearchSort(): array
	{
		// ID stabilizes both orders: without it the order of equal keys drifts and offset paging skips rows
		return match ($this)
		{
			self::Relevance => ['RANK' => 'DESC', 'DATE_CHANGE' => 'DESC', 'ID' => 'DESC'],
			self::Date => ['DATE_CHANGE' => 'DESC', 'ID' => 'DESC'],
		};
	}
}
