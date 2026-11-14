<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Integration\Intranet\AnnualSummary;

/**
 * Position of a metric halfway through a user: what is already counted and where to resume.
 *
 * The chat and the identifier are where the next step starts, both included: the identifier is the
 * left bound of the window not read yet, not a message of its own. The total carries the reactions
 * counted so far, the steps count the calls the user has already taken.
 */
final readonly class SummaryProgress
{
	public function __construct(
		public int $userId,
		public int $chatId,
		public int $messageId,
		public int $total,
		public int $steps,
	)
	{
	}
}
