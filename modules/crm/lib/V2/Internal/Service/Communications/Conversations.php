<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Communications;

use Bitrix\Crm\V2\Internal\Entity\Summary\TruncationReason;

/**
 * Conversations a read answers, and whether they are the whole of what the card holds.
 *
 * The second half is the point of the class. A read of a card is bounded twice - by the ceiling the
 * caller named and by the depth {@see CommunicationActivityFinder} walks - and a list shortened by
 * either of them is indistinguishable from the whole history of a quiet client. Answered as a bare
 * list, four conversations of a card that holds three hundred are read by an agent as "they talked
 * four times", which is not a cut of the answer but a false fact in it.
 *
 * The reason is the one every section of a summary is marked with, so whoever reads it reads one
 * vocabulary and not two ({@see TruncationReason}).
 *
 * The named constructors are the only way to build it, so a list that says nothing about its own
 * completeness does not exist.
 *
 * @internal
 */
final class Conversations
{
	/**
	 * @param array<int, array> $items The conversations themselves, the freshest first.
	 */
	private function __construct(
		public readonly array $items,
		public readonly ?TruncationReason $truncationReason,
	)
	{
	}

	/**
	 * The read saw the end of what it was reading and nothing was left behind.
	 *
	 * @param array<int, array> $items
	 */
	public static function whole(array $items): self
	{
		return new self($items, null);
	}

	/**
	 * More was found than the answer carries, so the tail of the list was dropped.
	 *
	 * @param array<int, array> $items Already cut down to the ceiling.
	 */
	public static function cutByTheCeiling(array $items): self
	{
		return new self($items, TruncationReason::ItemLimit);
	}

	/**
	 * The read stopped before the end of the card: what is answered is the recent part of the history
	 * and the conversations below it were never reached.
	 *
	 * @param array<int, array> $items
	 */
	public static function cutByTheDepthOfTheRead(array $items): self
	{
		return new self($items, TruncationReason::ReadDepth);
	}

	public function isTruncated(): bool
	{
		return $this->truncationReason !== null;
	}
}
