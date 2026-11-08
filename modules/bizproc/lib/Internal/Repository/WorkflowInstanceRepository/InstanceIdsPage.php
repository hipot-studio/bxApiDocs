<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Repository\WorkflowInstanceRepository;

/**
 * A page of instance ids and the place it ended at.
 *
 * The place travels as one opaque string on purpose: the order the pages are read in is the knowledge of
 * {@see WorkflowInstanceRepository} alone, and a caller that resumes reading hands back what it was given
 * without ever looking inside. That is also what lets the place be carried in the parameters of an agent,
 * where only integers and strings are allowed.
 */
final class InstanceIdsPage
{
	/**
	 * @param list<string> $ids ids of the page, in the order of the reading
	 * @param string $endToken the place the page ended at, to be handed back to read the next one; empty
	 *     for a page that holds nothing
	 * @param int $endsAt the moment of the last row of the page, as a whole number of seconds; zero for a
	 *     page that holds nothing. It is here for a caller that keeps the place across calls and cannot
	 *     afford to keep one inside a second that is still running: rows are ordered by the moment and then
	 *     by the id, and an id is not made in the order the rows are written.
	 */
	public function __construct(
		public readonly array $ids,
		public readonly string $endToken,
		public readonly int $endsAt = 0,
	)
	{
	}
}
