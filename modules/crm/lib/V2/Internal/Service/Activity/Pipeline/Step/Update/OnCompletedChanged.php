<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Acts on an activity that has just been finished or reopened: row 23 of the owner table STEP-UPDATE
 * (crm_activity.php:786-795), the THIRD branch of the exclusive block D3 - and the one row of the block that also
 * does something outside it.
 *
 * TWO THINGS HAPPEN HERE AND THEY HAVE DIFFERENT CONDITIONS, which is the one place V2 leaves the exclusive block
 * on purpose (row D4-PING of the registry of intentional deviations).
 *
 * The queue of the reminders of an activity is built from its completion and its deadline, so a completion that
 * changed makes the queue wrong - ALWAYS, whatever else the same update did. Legacy raises the flag of that
 * synchronization inside the third branch of its `if / elseif / elseif`, so it loses it in the two cases where an
 * earlier branch fires: a completion changed together with the person responsible, and a completion changed by an
 * operation that also named the incoming-channel marker (LBS-1 section 15 item 3). Both leave reminders standing on
 * a finished activity, or none on a reopened one. V2 raises the flag on the change itself.
 *
 * The rest of the branch stays exclusive and reads exactly as legacy has it: an activity that is in the registry of
 * incoming channels has its row rewritten with the new completion, and the aggregate of the uncompleted activities
 * is marked for recomputation. Neither is lost when this branch is suppressed - the branch that suppressed it
 * performs the same registration and raises the same flag - which is why the deviation is about the queue alone.
 *
 * THE SUPPRESSION IS ASKED, NOT REMEMBERED. Whether an earlier branch of the block fired is computed here from the
 * same fixed facts those branches compute it from: the marker the operation named, left behind by row 20 as a value
 * of the run, and the person responsible, compared between the two states of the update
 * ({@see OnResponsibleChanged::hasTheResponsibleChanged()} - the question has one owner and one answer). So the
 * block cannot be broken by the order a pipeline declares its steps in, and this row cannot silently start acting
 * for two branches at once.
 *
 * THE COMPLETION IS COMPARED THE WAY LEGACY COMPARES IT: the state before the update against the state re-read
 * after it, an activity that says nothing about it counting as unfinished (:744, :751). The status of the activity
 * takes no part - it narrows "finished" for the statistics of the rows below and never for this comparison.
 *
 * @internal
 */
final class OnCompletedChanged implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'OnCompletedChanged';
	}

	/**
	 * Every update whose completion changed, suppressed or not: the synchronization of the queue of the reminders
	 * belongs to the change and not to the branch. What the branch itself does is decided inside.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivityId() <= 0 || !$context->hasPreviousState())
		{
			return false;
		}

		return self::hasTheCompletionChanged($context);
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$context->setRunValue(PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC, true);

		if (self::isSuppressedByAnEarlierBranch($context))
		{
			return $result;
		}

		$activity = $context->getActivity();

		if ($activity->isIncomingChannel() === true)
		{
			IncomingChannel::getInstance()->register(
				$activityId,
				(int)$activity->getResponsibleId(),
				$activity->isCompleted() === true,
			);
		}

		$context->setRunValue(PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH, true);

		return $result;
	}

	/**
	 * Legacy interrupts an update on the state it loads, on the check of the fields and on the re-read of the row -
	 * and on nothing else.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Whether the activity was finished or reopened by this update.
	 */
	private static function hasTheCompletionChanged(PipelineContext $context): bool
	{
		return ($context->getPreviousActivity()->isCompleted() === true)
			!== ($context->getActivity()->isCompleted() === true);
	}

	/**
	 * Whether one of the two branches above this one fired - the conditions of those branches, asked here.
	 */
	private static function isSuppressedByAnEarlierBranch(PipelineContext $context): bool
	{
		if ($context->hasRunValue(PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL))
		{
			return true;
		}

		return OnResponsibleChanged::hasTheResponsibleChanged($context);
	}
}
