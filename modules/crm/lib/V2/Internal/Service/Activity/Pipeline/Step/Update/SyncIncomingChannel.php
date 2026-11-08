<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Puts the activity into the registry of incoming channels or takes it out of it, because the operation said so:
 * row 21 of the owner table STEP-UPDATE (crm_activity.php:759-772), the FIRST branch of the exclusive block D3.
 *
 * THE BLOCK IS THREE STEPS AND AT MOST ONE OF THEM ACTS. Legacy writes it as one `if / elseif / elseif` over three
 * questions asked in a fixed order - did the operation name the marker, did the person responsible change, did the
 * completion change - and the order is the whole meaning of it: an operation that named the marker is taken at its
 * word and the two changes below are not even looked at. Three steps cannot be an `elseif`, so each of them carries
 * the full condition of its branch, the "otherwise" of the ones above included, and each computes it from facts
 * that are already fixed by the time the block starts. Exclusion is therefore an invariant of the conditions and
 * not of the order in which a pipeline happens to declare them: no assembly can make two of the three act.
 *
 * THIS BRANCH IS SELECTED BY WHAT THE OPERATION SAID, not by what the model now holds. Row 20 has written the
 * marker into the state of the run whatever the operation said, so the model can no longer be asked; the value of
 * the run left behind by that row can ({@see PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL}), and carrying it
 * at all is what legacy asks with `isset()` on its field array.
 *
 * THE MARKER HAS TWO STATES HERE AND THREE IN LEGACY. A named "yes" registers the activity with the person
 * responsible for it and with its completion, a named "no" removes it from the registry - and legacy also has the
 * case of a value that is neither, which does neither of the two and still counts as a named marker (:763-772).
 * The typed model cannot express that third value at all, so the branch is a plain either-or.
 *
 * THE REGISTRATION IS AN UPSERT and reads the two facts beside it off the state of the run, which past row 17 is
 * the row as it now stands: legacy reads the completion off its re-read snapshot and the person responsible off a
 * value it resolved as "what the caller gave, else what the row had" (:738-742) - which is what the re-read row
 * holds.
 *
 * @internal
 */
final class SyncIncomingChannel implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'SyncIncomingChannel';
	}

	/**
	 * The first branch of the block: an operation that named the marker. Nothing else is asked - this branch
	 * suppresses both of the ones below, and they know it.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		return $context->hasRunValue(PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL);
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$activity = $context->getActivity();
		$channel = IncomingChannel::getInstance();

		if ($context->getRunValue(PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL) === true)
		{
			$channel->register(
				$activityId,
				(int)$activity->getResponsibleId(),
				$activity->isCompleted() === true,
			);
		}
		else
		{
			$channel->unregister($activityId);
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
}
