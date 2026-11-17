<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Time\Service;

/**
 * Result of the deadline back-calculation (ALG-01):
 * the instance creation time (used as NEXT_EXECUTION_TIME) and the on-pattern
 * deadline it was derived from (used to stamp the instance DEADLINE).
 *
 * Both values are server-local unix timestamps, consistent with the schedule path.
 */
final readonly class DeadlineCreationTime
{
	public function __construct(
		public int $creationTime,
		public int $deadline,
	)
	{
	}
}
