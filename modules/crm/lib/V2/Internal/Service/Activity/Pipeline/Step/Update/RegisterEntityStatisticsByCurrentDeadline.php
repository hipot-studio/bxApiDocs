<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompletionForStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EntityStatisticsOfADay;
use Bitrix\Main\Result;

/**
 * Counts anew the day the activity is now due on, for the objects it is now bound to: row 27 of the owner table
 * STEP-UPDATE (crm_activity.php:841-861), the second half of the double statistics of an update (row D6 of the
 * registry of intentional deviations).
 *
 * ITS GATE IS SHORTER THAN THE ONE OF THE ROW ABOVE, and that is legacy exactly (:842): complete for the
 * statistics and a deadline, with nothing about anything having moved. The day of the current deadline is counted
 * on every update of a finished activity, whether that update touched a count or not - the count is re-read out of
 * the rows of the day either way, so the repetition is idempotent and legacy spends it.
 *
 * WHY IT IS NOT THE STEP OF THE SAME NAME OF AN ADD, and the difference is in the question rather than in the
 * effect. `Step\Add\RegisterEntityStatistics` asks the RUN whether a deadline was given - the column of the
 * transit, plus the fact of a deadline forced to none - because on an add that column is the only place the
 * deadline exists in. An update strips the deadline of the caller before its provider is asked, so its run carries
 * no such column on nearly every update, and both of its deadlines are read off its two snapshots. Handing an
 * update the gate of an add would silently stop counting the days of every update of the portal, and there is no
 * mode to declare here: it is not one step configured twice but two questions with one answer between them
 * ({@see EntityStatisticsOfADay}).
 *
 * THE SENTINEL NEEDS NO SECOND QUESTION HERE. On an add the sentinel of "no deadline" can stand in the column, so
 * the outer gate and the per-binding rule are two questions; the model of a state has it resolved to null by the
 * boundary of the storage, and null is precisely what legacy's own two questions arrive at together - the outer
 * one passes and every binding is then refused.
 *
 * THE SET OF BINDINGS IS THE CURRENT ONE, which after row 18 is what the model carries: the effective set of a
 * write that moved, the set the caller gave where it did not, and the snapshot of row 4 where the caller named
 * none. There is no fallback to the previous state to be written here - row 18 answers that question for every
 * update.
 *
 * @internal
 */
final class RegisterEntityStatisticsByCurrentDeadline implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'RegisterEntityStatisticsByCurrentDeadline';
	}

	/**
	 * The gate of legacy over the re-read state: complete for the statistics, and a deadline that is a real one.
	 * The previous state takes no part - this row is about the day the activity is on now.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$activity = $context->getActivity();

		return CompletionForStatistics::isComplete($activity) && $activity->getDeadline() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();
		$deadline = $activity->getDeadline();
		if ($deadline === null)
		{
			// The activity has no deadline: the per-binding rule of legacy refuses every owner under that.
			return new Result();
		}

		EntityStatisticsOfADay::reAggregate($activity->getBindings() ?? [], $deadline);

		return new Result();
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
