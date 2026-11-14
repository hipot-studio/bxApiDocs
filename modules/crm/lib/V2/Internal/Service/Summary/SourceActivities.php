<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

/**
 * The activities of the sources of a summary, read once and marked with who asked for them - and,
 * beside them, whether they were read at all and whether the read reached the end of their calls.
 *
 * The second half is what the class exists for. {@see CommunicationsReader} answers two of its kinds
 * entirely out of these rows and sends no strategy to look for them, so "the sources hold none of this
 * kind" and "nobody read the sources" have to be told apart: taken for the first, the second answers a
 * client that has transcripts and to-do items as a client that has neither, and the section is not even
 * marked as incomplete.
 *
 * Carried as a bare list, the two states differ by an empty array, and an empty array is what a double
 * of the reader answers with when nobody told it what to answer - so a test or a service that
 * substitutes the reader and keeps the real section silently loses two kinds of it. Carried as this,
 * "not read" has a name of its own and the double that was told nothing fails where it is written
 * instead.
 *
 * @internal
 */
final class SourceActivities
{
	/**
	 * @param array<int, array> $items The activities themselves, those of one source following each
	 * other, the freshest of a source first.
	 * @param bool $areOlderCallsUnsearched The sources have calls below the window
	 * {@see LastCallSummaryReader::read()} looks through, so a search that found no summary looked at a
	 * part of the history and not at the whole of it.
	 */
	private function __construct(
		public readonly array $items,
		public readonly bool $areRead,
		public readonly bool $areOlderCallsUnsearched,
	)
	{
	}

	/**
	 * The sources were read, and these are their activities - the empty list included, which says the
	 * sources hold none of the kinds the read covers.
	 *
	 * @param array<int, array> $items
	 */
	public static function read(array $items, bool $areOlderCallsUnsearched = false): self
	{
		return new self($items, true, $areOlderCallsUnsearched);
	}

	/**
	 * Nobody read the sources, so every kind looks for its own records the way it always has.
	 */
	public static function unread(): self
	{
		return new self([], false, false);
	}
}
