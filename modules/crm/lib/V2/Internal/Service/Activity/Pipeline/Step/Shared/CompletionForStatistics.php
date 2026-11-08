<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;

/**
 * Whether an activity counts as complete where the statistics are concerned: the flag of the completion,
 * narrowed by the status of it (crm_activity.php:275-278 on an add, :744-749 and :751-756 on an update).
 *
 * THIS IS THE ONE DEFINITION OF THAT NOTION, and it has to be one, because rows of both owner tables ask it: the
 * re-aggregation of the day of the bound deals and leads of an add and the two rows doing the same on an update,
 * the aggregate of the uncompleted activities, which is this answer negated, and the queue of the reminders, which
 * an activity finished for the statistics gets none of. Legacy computes it once per pipeline - into
 * `$completed`, `$prevCompletedForStatistics` and `$curCompletedForStatistics` - and reads each of those in
 * several places, so a second derivation anywhere would be a second answer about one activity.
 *
 * IT IS NOT THE PAIR OF THE COMPLETION, and an update is where that becomes observable: an activity finished by
 * hand and now marked as finished by automation kept its flag and left these counts, so
 * {@see ActivityChangeSet::isCompleted} answers "unchanged" while this answer moved. A row of an update needing
 * the change therefore asks this predicate of both of its states and never the set of changes.
 *
 * A STATUS NOBODY NAMED LEAVES THE FLAG TO DECIDE - the `!isset($arFields['STATUS'])` of legacy. A state read back
 * from storage always carries a status, so that half of the rule concerns a caller of an add alone.
 *
 * @internal
 */
final class CompletionForStatistics
{
	public static function isComplete(Activity $activity): bool
	{
		if ($activity->isCompleted() !== true)
		{
			return false;
		}

		$status = $activity->getCompletionStatus();

		return $status === null || $status === CompletionStatus::Completed;
	}
}
