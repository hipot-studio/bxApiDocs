<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Replication\Template\Time\Service;

final readonly class OccurrenceSearchResult
{
	public function __construct(
		public DeadlineCreationTime $occurrence,
		public int $skipped,
	)
	{
	}
}
