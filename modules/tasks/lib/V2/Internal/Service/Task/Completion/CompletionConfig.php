<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Completion;

final class CompletionConfig
{
	public function __construct(
		public readonly bool $completeCheckListForFinalTask = true,
		public readonly bool $useBatchCheckListCompletion = false,
	)
	{
	}
}
