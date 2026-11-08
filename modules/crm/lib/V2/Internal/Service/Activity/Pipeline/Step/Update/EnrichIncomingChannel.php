<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\IncomingChannel;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Writes the incoming-channel marker into both states of the update: row 20 of the owner table STEP-UPDATE
 * (crm_activity.php:761, :770, :775-776).
 *
 * THE MARKER IS NO COLUMN OF AN ACTIVITY, and that is the whole reason this row exists. Whether somebody reached
 * the company through this activity and has not been answered yet lives in a registry of its own, so neither the
 * state row 1 read nor the state row 17 re-read carries it - and five rows below this one read it off those very
 * states: the badges, the counters, the search index, the history of the CRM and the event of the calendar.
 * Legacy fills the two field arrays in by hand for exactly that reason, and it does so on every path through the
 * block: whichever branch of the exclusive block below fires, both arrays leave the block carrying the marker.
 * This row is therefore unconditional and is NOT part of that exclusive block.
 *
 * WHERE THE TWO VALUES COME FROM. An operation that named the marker is saying what the activity is to become, so
 * the state after it carries what was named (:770) and the state before it carries what the registry still holds
 * (:761) - the two differ, and that difference is the change of the operation. An operation that said nothing about
 * it makes no such change, so both states carry the same thing, and it is what the registry holds (:775-776).
 *
 * WHY THE RUN HAS TO REMEMBER WHAT WAS NAMED. Writing the marker into the state of the run destroys the one fact
 * the three rows below select their branch by: past this row, a model carrying "yes" may be carrying the answer of
 * the caller or the answer of the registry, and legacy tells them apart by asking its field array. So the marker as
 * the operation named it is left behind as a value of the run
 * ({@see PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL}), carried only where an operation named one. Nothing
 * else in the run answers that question: the marker is a field of the typed model and no column of the transit, and
 * the representation the caller gave holds the serialized fields and the storage columns rather than the model.
 *
 * @internal
 */
final class EnrichIncomingChannel implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. There is one marker to write and two
	 * states to write it into, and one of them is missing.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'EnrichIncomingChannel';
	}

	/**
	 * Every update enriches both of its states. The conditions of the exclusive block below belong to the steps of
	 * that block, and none of them is a condition of this one.
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

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$given = $context->getActivity()->isIncomingChannel();
		$registered = IncomingChannel::getInstance()->isIncomingChannel($activityId);

		if ($given !== null)
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL, $given);
		}

		$context->getActivity()->internalSet(Activity::isIncomingChannel, $given ?? $registered);
		$context->getPreviousActivity()->internalSet(Activity::isIncomingChannel, $registered);

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
