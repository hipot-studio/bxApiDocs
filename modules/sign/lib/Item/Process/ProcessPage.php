<?php

namespace Bitrix\Sign\Item\Process;

use Bitrix\Sign\Contract;

/**
 * A page of the list of processes. The flag of the next page is built together with the rows and not
 * derived from their number: visibility is decided per row, so a page shorter than asked for does not mean
 * the list has ended.
 */
final class ProcessPage implements Contract\Item
{
	/**
	 * @param list<LaunchGroup>|list<RunningProcess> $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly bool $hasNextPage,
	)
	{
	}

	public static function emptyPage(): self
	{
		return new self([], false);
	}
}
