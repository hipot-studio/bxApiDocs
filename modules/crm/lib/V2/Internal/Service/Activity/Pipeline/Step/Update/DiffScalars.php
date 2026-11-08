<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers what an update did to the single-valued fields of the activity: the first row of the `Diff` family of
 * the owner table STEP-UPDATE, standing above the deadline of the row below it. Legacy has no statement of its own
 * here - it compares two whole rows and hands whole rows on (crm_activity.php:738-756, :866, :993) - so the row
 * carries no anchor of a fragment and no effect but the pairs it supplies.
 *
 * WHY THE ROW EXISTS. A set of changes answers "unchanged" for a name nobody supplied a pair of
 * ({@see ActivityChangeSet::isChanged()}), and four names of it had readers and no producer at all: the person
 * responsible, the completion, the status of that completion and the marker of the incoming channel. The rows
 * below were therefore told the activity never changes hands - the subscription of the activity stream, the
 * notification of the assignment and the layer of the stream itself all ask that very question - and the wrapper
 * of a legacy provider handed its providers a row with no completion and no status in it, where legacy hands them
 * the whole row it re-read. The rows of the exclusive block above cannot own the pairs: they act on some updates
 * and a pair has to be there on all of them.
 *
 * IT RAISES NO FLAG OF THE RUN, and that is the difference between a differ and the block above it. The queue of
 * the reminders and the aggregate of the uncompleted activities are raised where legacy raises them - inside the
 * exclusive block and inside the two rows below this one - each under a condition of its own. A differ raising
 * them again would rebuild both on every update that changed hands, which is neither legacy nor a fix of it.
 *
 * WHAT IT DOES NOT OWN EITHER. The deadline and the reminder are single values too, and each of them is a row of
 * its own precisely because a change of it costs something: the queue, the aggregate, or both. The sets of an
 * activity are no scalars and belong to the rows that write them - the bindings to row 18, the communications to
 * row 19. A pair belongs here when it is a single value of the state, when every update has to carry it, and when
 * the fact of its change costs nothing by itself.
 *
 * THE QUESTION OF THE BLOCK ABOVE IS NOT ANSWERED A SECOND TIME. Whether the activity changed hands is asked by
 * the three branches of the block above through {@see OnResponsibleChanged::hasTheResponsibleChanged()}, which
 * they need before this row has run, so the answer stays there. The pair supplied here carries the same two
 * values, cast the same way, out of the same two states that nothing between the block and this row touches: the
 * two can differ only if somebody moves this row above the block, which the owner table does not.
 *
 * THE PAIRS ARE DIFFERENCES OF THE TWO STATES AND NEVER OF WHAT THE OPERATION NAMED. The marker of the incoming
 * channel is the clearest case of it: row 20 writes the marker into both states - out of the registry, or out of
 * what the operation named - so a difference of the two states is what this row reports, and the separate question
 * "did the operation name one" has an answer of its own under
 * {@see PipelineContext::RUN_VALUE_GIVEN_INCOMING_CHANNEL}.
 *
 * THE STATUS OF A COMPLETION IS NOT THE COMPLETION, and neither of the two is "complete for the statistics" - the
 * flag narrowed by the status of a finished activity (:745-756), which is what the two rows registering the
 * statistics gate on. That third notion is theirs and is derived there; this row supplies the two values it is
 * derived from and says nothing about it.
 *
 * @internal
 */
final class DiffScalars implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in. There is nothing to compare against.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'DiffScalars';
	}

	/**
	 * Every update supplies the pairs. A reader of them stands below every branch and every gate of this
	 * pipeline, so a condition here would be a name missing from the set rather than an effect not performed.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$previous = $context->getPreviousActivity();
		$activity = $context->getActivity();

		$context->getChanges()
			->set(
				ActivityChangeSet::responsibleId,
				(int)$previous->getResponsibleId(),
				(int)$activity->getResponsibleId(),
			)
			->set(
				ActivityChangeSet::isCompleted,
				self::completionOf($previous),
				self::completionOf($activity),
			)
			->set(
				ActivityChangeSet::completionStatus,
				self::statusOf($previous),
				self::statusOf($activity),
			)
			->set(
				ActivityChangeSet::isIncomingChannel,
				self::markerOf($previous),
				self::markerOf($activity),
			)
		;

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
	 * An activity that says nothing about its completion is not complete, which is how legacy reads the column
	 * of either of its two rows (:744, :751).
	 */
	private static function completionOf(Activity $activity): bool
	{
		return $activity->isCompleted() === true;
	}

	/**
	 * A state whose row was written past every pipeline of the module carries no status of a completion, and
	 * that is a value of its own rather than a gap: legacy compares the column as it stands.
	 */
	private static function statusOf(Activity $activity): CompletionStatus
	{
		return $activity->getCompletionStatus() ?? CompletionStatus::Undefined;
	}

	/**
	 * The marker as row 20 left it in the state. It is filled into both states unconditionally, so a state
	 * without one is a run whose row 20 did not perform - and "no marker" is the answer legacy gives there too.
	 */
	private static function markerOf(Activity $activity): bool
	{
		return $activity->isIncomingChannel() === true;
	}
}
