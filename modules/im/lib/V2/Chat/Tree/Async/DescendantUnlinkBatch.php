<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Chat\Tree\Async;

/**
 * Result of one batch of the department down-cascade.
 */
final class DescendantUnlinkBatch
{
	public function __construct(
		public readonly bool $hasMore,
		public readonly int $lastId,
	)
	{
	}
}
