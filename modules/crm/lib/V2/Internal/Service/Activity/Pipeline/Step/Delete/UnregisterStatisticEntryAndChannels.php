<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Integration\Channel\ActivityChannelBinding;
use Bitrix\Crm\Statistics\ActivityStatisticEntry;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Takes the statistic record of the activity itself and its bindings to the external channels off: row 15 of the
 * owner table STEP-DELETE (crm_activity.php:1478-1479).
 *
 * THE ROW STANDS OUTSIDE THE DEMAND TO SKIP THE STATISTICS, AND THAT IS REPRODUCED AS THE DEFECT IT IS (item E7).
 * Row 12 recomputes the aggregates of the objects the activity was bound to and obeys that demand; these two
 * registrations are keyed by the activity itself and legacy performs them unconditionally, two lines below the block
 * the demand closes. So the mass operations of the portal that switch the statistics off still lose these two rows -
 * bug-for-bug, and the registry of intentional deviations has no line letting it be tidied up.
 *
 * IT IS NOT A DUPLICATE OF ROW 9. Four registries are keyed by an activity and row 9 removes the other three - the
 * mark of an unanswered incoming channel, the reminder offsets and the queue built out of them. These are the record
 * of the day the activity was due on (`b_crm_act_stat`) and the bindings of the activity to the channels it arrived
 * through, together with the statistics of those channels: different tables, different writers, and legacy removes
 * them in a place of their own.
 *
 * THE ROW RUNS BELOW ROWS 13 AND 14, AND THE ORDER IS NOT NEGOTIABLE. Legacy performs the provider disposing of the
 * projection (:1465) and the removal of the calendar event (:1468-1476) before these two lines, and lifting them
 * above changes the order of observable effects with no line of the registry allowing it - which makes a
 * rearrangement a defect rather than a tidy-up (the key constraints of `[P5.T5]`).
 *
 * @internal
 */
final class UnregisterStatisticEntryAndChannels implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. Both
	 * registries are keyed by the activity - one of them refuses a non-positive key outright - so there is nothing
	 * to remove.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'UnregisterStatisticEntryAndChannels';
	}

	/**
	 * Every delete performs this. See the note of the class on why the demand to skip the statistics is not asked.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		ActivityStatisticEntry::unregister($activityId);
		ActivityChannelBinding::unregisterAll($activityId);

		return $result;
	}

	/**
	 * An activity whose registrations could not be removed is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
