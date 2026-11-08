<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Moves the row of the registry of incoming channels to the person the activity is now on: row 22 of the owner
 * table STEP-UPDATE (crm_activity.php:777-785), the SECOND branch of the exclusive block D3.
 *
 * THE CONDITION CARRIES ITS OWN "OTHERWISE". The branch fires for an update that did NOT name the incoming-channel
 * marker and did change the person responsible; the first half is the `else` of the branch above, and it is asked
 * here rather than remembered from there, because an `elseif` of legacy has to become an invariant of the
 * conditions when it becomes three steps. Both halves are facts fixed before the block starts - the marker the
 * operation named is a value of the run left by row 20, and the person responsible is compared between the state
 * read before the write and the state re-read after it - so no order of declaration can make this branch act
 * together with either of its neighbours.
 *
 * WHY THE REGISTRY IS TOUCHED AT ALL. Its row holds the person responsible for answering, so an activity that
 * changed hands has a row pointing at the wrong person; the registration is an upsert and rewrites it. An activity
 * that is not in the registry is left alone - legacy asks the marker of the state after the update (:781), which
 * row 20 has filled in from the registry for exactly this reason.
 *
 * THE COMPARISON IS THE ONE LEGACY MAKES, THROUGH INTEGERS. Legacy casts what the caller gave and compares it
 * loosely against the column of the previous state, where a numeric string and a number are the same person; the
 * typed model has an integer on both ends, and an activity with nobody on it reads as zero on both. The state after
 * the write is asked rather than the input, as row D1 of the registry demands - a subscriber of the event of row 11
 * may have moved the activity to somebody else, and legacy would miss that.
 *
 * @internal
 */
final class OnResponsibleChanged implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'OnResponsibleChanged';
	}

	/**
	 * An update that named no marker and moved the activity to somebody else.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ($context->getActivityId() <= 0 || !$context->hasPreviousState())
		{
			return false;
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL))
		{
			return false;
		}

		return self::hasTheResponsibleChanged($context);
	}

	/**
	 * Whether the activity is on somebody else than it was. The question belongs to the block rather than to one
	 * step of it: the branch below asks it too, to know it is suppressed.
	 */
	public static function hasTheResponsibleChanged(PipelineContext $context): bool
	{
		return (int)$context->getPreviousActivity()->getResponsibleId()
			!== (int)$context->getActivity()->getResponsibleId();
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
}
