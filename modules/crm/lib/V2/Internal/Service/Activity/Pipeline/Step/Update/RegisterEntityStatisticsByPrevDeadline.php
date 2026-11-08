<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompletionForStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EntityStatisticsOfADay;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Counts anew the day the activity used to be due on, for the objects it used to be bound to: row 26 of the owner
 * table STEP-UPDATE (crm_activity.php:815-839), the first of the two halves of the double statistics of an update
 * (row D6 of the registry of intentional deviations, reproduced as it is).
 *
 * WHY AN UPDATE COUNTS TWO DAYS. The counts of a day are read out of the rows of the activities of that day, so
 * moving an activity away from a day leaves the count of the day it left standing. This row is the repair of that:
 * it re-reads the day of the PREVIOUS deadline over the PREVIOUS set of bindings, and the row below it does the
 * same over the current ones. Neither of the two knows about the other, and both are needed - an update that moved
 * the deadline changes both days, an update that moved the bindings changes the same day for two different sets of
 * owners.
 *
 * WHAT MAKES IT APPLICABLE, in the order legacy asks it (:816-820): the activity was complete for the statistics
 * BEFORE the update, it had a deadline BEFORE it, and something happened that can have moved a count - the set of
 * bindings, the completion for the statistics, or the deadline. The last three are the questions of three other
 * rows and are asked of the set of changes rather than derived again: the bindings of row 18
 * ({@see ActivityChangeSet::bindings} - the comparison legacy keeps in `$bindingsChanged`, not the flag of the
 * aggregate, which four rows raise) and the deadline of row 24 ({@see ActivityChangeSet::deadline}).
 *
 * "COMPLETE FOR THE STATISTICS" IS THE THIRD NOTION AND ITS OWNER IS {@see CompletionForStatistics}. It is neither
 * the flag of the completion nor its status, so the set of changes cannot answer it: an activity finished by hand
 * and now finished by automation moved neither the flag nor - as far as any pair goes - anything this row could
 * read, while its statistics did change. Both states are therefore asked the same predicate, exactly as legacy
 * derives its two variables from its two rows by one and the same three lines.
 *
 * THE DEADLINE IS THE ONE OF THE STATE AND NOT OF THE RUN. An update strips the deadline of the caller before the
 * provider is asked, so the run usually carries no such column at all, and the fact of a forced "no deadline"
 * ({@see PipelineContext::RUN_VALUE_DEADLINE_NOT_GIVEN}) says nothing about the row - legacy reads both of its
 * deadlines off its two snapshots. The model answers null for the sentinel of "no deadline" as well as for a NULL
 * column, and that collapses the two questions of legacy without changing an answer: under the sentinel legacy
 * passes its outer gate and then refuses every binding by `!IsMaxDatabaseDate`, so nothing is registered either
 * way, and a NULL column is empty for legacy too.
 *
 * THE SET OF BINDINGS IS THE ONE THAT WAS THERE BEFORE - the snapshot row 4 took before the write, which lives in
 * the model of the previous state and nowhere else. The pair of the set of changes may not stand in for it: it
 * carries the set the caller gave, not the effective one, and the "before" side of it is that same snapshot.
 *
 * @internal
 */
final class RegisterEntityStatisticsByPrevDeadline implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in. Both the gate of this row and the day it
	 * counts are that state, so there is nothing to register.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'RegisterEntityStatisticsByPrevDeadline';
	}

	public function isApplicable(PipelineContext $context): bool
	{
		if (!$context->hasPreviousState())
		{
			return false;
		}

		$previous = $context->getPreviousActivity();
		if (!CompletionForStatistics::isComplete($previous) || $previous->getDeadline() === null)
		{
			return false;
		}

		return self::hasAnythingMovedTheCount($context);
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
		$deadline = $previous->getDeadline();
		if ($deadline === null)
		{
			// The activity had no deadline: the per-binding rule of legacy refuses every owner under that.
			return $result;
		}

		EntityStatisticsOfADay::reAggregate($previous->getBindings() ?? [], $deadline);

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
	 * Whether this update can have moved a count of the day it is leaving (:819): the three questions of legacy,
	 * asked of their owners.
	 */
	private static function hasAnythingMovedTheCount(PipelineContext $context): bool
	{
		$changes = $context->getChanges();

		return $changes->isChanged(ActivityChangeSet::bindings)
			|| $changes->isChanged(ActivityChangeSet::deadline)
			|| CompletionForStatistics::isComplete($context->getPreviousActivity())
				!== CompletionForStatistics::isComplete($context->getActivity())
		;
	}
}
