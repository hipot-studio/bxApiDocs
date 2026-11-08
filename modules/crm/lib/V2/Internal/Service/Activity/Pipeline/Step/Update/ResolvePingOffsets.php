<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\Provider\Eventable\PingOffset;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Settles which reminders the activity has after this update, and says what that costs the effects below: row 7
 * of the owner table of an update (crm_activity.php:582-595).
 *
 * IT IS A DIFFERENT STEP FROM THE ONE OF AN ADD AND THE DIFFERENCE IS THE STORE. An add has no stored offsets to
 * fall back on, so it asks the provider for its defaults; an update never asks the provider at all. Either the
 * operation named the offsets - and then they are written into the store here and the run learns that two effects
 * far below it are now out of date - or it said nothing about them, and the offsets of the run are simply the ones
 * the store already holds (:594). Nothing is registered in that second case, and nothing is stale because of it.
 *
 * THE OFFSETS OF THE RUN ARE THE RESOLVED ONES, AND THE MODEL KEEPS WHAT THE CALLER SAID. Legacy holds the
 * resolved offsets in a local variable it never writes back into its field array, so the subscribers of the event
 * of a changed activity see the offsets of the caller and not these; the steps that need the real ones read
 * {@see PipelineContext::RUN_VALUE_PING_OFFSETS}, which this step is the only producer of in an update - the
 * light counter of row 32 among them.
 *
 * TWO FACTS TRAVEL BESIDE THE OFFSETS, and they are the reason the naming of offsets is not a local matter. New
 * offsets mean the queue of the reminders no longer matches the activity, and they mean the aggregate of the
 * uncompleted activities has to be recomputed; legacy keeps both in local booleans of its pipeline
 * (`$needSynchronizePingQueue`, `$needRegisterUncompletedActivities`) and the rows 40 and 33 act on them. In V2
 * they are values of the run, raised here and never lowered
 * ({@see PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC}).
 *
 * THE GATE IS NOT LIFTED OUT OF THE BODY, exactly as it is not on an add. The step runs on every update, because
 * the branch that names no offsets is the branch that fills the run with the stored ones, and a step gated on
 * "the caller named offsets" would leave row 32 with nothing to read on every update that does not.
 *
 * @internal
 */
final class ResolvePingOffsets implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity to update. The offsets are keyed by the activity, so there is
	 * neither anything to register nor anything to read.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'ResolvePingOffsets';
	}

	/**
	 * Every update settles its reminders. See the note of the class on why no half of the resolution is asked here.
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

		$offsets = $context->getActivity()->getPingOffsets();
		if ($offsets === null)
		{
			$context->setRunValue(
				PipelineContext::RUN_VALUE_PING_OFFSETS,
				PingOffset::getInstance()->getOffsetsByActivityId($activityId),
			);

			return $result;
		}

		PingOffset::getInstance()->register($activityId, $offsets);

		$context->setRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS, $offsets);
		$context->setRunValue(PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC, true);
		$context->setRunValue(PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH, true);

		return $result;
	}

	/**
	 * Legacy checks nothing here: an update whose reminders could not be settled is written all the same.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
