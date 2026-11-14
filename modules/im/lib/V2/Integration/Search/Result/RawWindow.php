<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Search\Result;

use Bitrix\Im\V2\Integration\Search\SearchRecord;

/**
 * One window of the raw output of the engine: what it delivered and how far it travelled.
 *
 * The two are not the same number. CSearch::Fetch() calls itself again on a hit whose row of
 * b_search_content is gone, so a window of forty positions may deliver fifteen records, and taking
 * the delivered ones for the travelled ones ends the paging on a portion that had matches behind it
 * and sends the next offset backwards.
 */
final class RawWindow
{
	/**
	 * @param array<int, SearchRecord> $records Keyed by the position of the record inside the window.
	 * @param int $positions Positions of the engine the window covered, the skipped ones included.
	 */
	public function __construct(
		public readonly array $records,
		public readonly int $positions,
	)
	{}
}
