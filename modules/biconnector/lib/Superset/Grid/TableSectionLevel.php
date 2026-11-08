<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Superset\Grid;

/**
 * The level of the tables section: what the reads of it are narrowed down to.
 *
 * The codes always answer the question "may this table be drawn"; the groups answer the same
 * question in the language of the query, and only while the two answers coincide.
 */
final readonly class TableSectionLevel
{
	/**
	 * @param string[]|null $tableCodes Codes of the level; null leaves the reads as they were before
	 *                                  the axis, an empty list means nothing at all is visible.
	 * @param int[]|null $groupIds Groups whose composition is exactly those codes, so that a query may
	 *                             name the groups instead of listing the codes; null while it may not.
	 */
	public function __construct(
		public ?array $tableCodes = null,
		public ?array $groupIds = null,
	)
	{
	}
}
