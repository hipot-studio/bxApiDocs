<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Comment;

/**
 * One page of the thread list: the cells the client shows, the answer to "is there more" and the
 * place in the sequence the next page starts at.
 *
 * Both answers are decided by the window the database returned and not by the number of cells left
 * after the filters: an empty thread dropped from a full window still consumes its place in the
 * ordered sequence the offset counts. The client cannot derive that place from the cells it got -
 * a window filtered down to nothing would leave it asking for the same offset forever - so the page
 * names it itself.
 */
final class ThreadListPage
{
	/**
	 * @param ThreadListItem[] $items
	 */
	public function __construct(
		public readonly array $items,
		public readonly bool $hasNextPage,
		public readonly int $nextOffset,
	) {}

	/**
	 * @return int[]
	 */
	public function getRootMessageIds(): array
	{
		return array_map(static fn (ThreadListItem $item): int => $item->parentMessageId, $this->items);
	}

	public function toRestFormat(): array
	{
		return array_map(static fn (ThreadListItem $item): array => $item->toRestFormat(), $this->items);
	}
}
