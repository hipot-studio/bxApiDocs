<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompletionForStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EntityStatisticsOfADay;
use Bitrix\Main\Result;

/**
 * Re-aggregates the day of every deal and lead the activity is bound to: step 18 of the owner table of an add
 * (crm_activity.php:274-301), checklist item C7.1.
 *
 * WHAT THE RE-AGGREGATION IS AND WHY A PROVIDER ACTIVITY GAINS NOTHING FROM IT is the note of
 * {@see EntityStatisticsOfADay}, which is where the effect itself lives: the same loop stands in three fragments
 * of legacy - here and in the two rows of an update - so it is stated once and this step is the gate of it.
 *
 * THE GATE HAS TWO HALVES AND ONE OF THEM IS PER BINDING. The outer one asks whether the activity is complete
 * for the purposes of the statistics and whether a deadline was given at all; the inner one asks, for each
 * binding, whether the owner is a real one and whether the value is not the sentinel of "no deadline". They may
 * not be collapsed into one question, which is what {@see DeadlineSentinelGuard} exists for: the sentinel a run
 * re-read from storage passes the outer gate - the step is applicable - and answers "no deadline" to the inner
 * one, so nothing is registered. A run whose deadline was forced to none does not even reach that: it is the one
 * shape of "the sentinel is in the column" that legacy has no key for at all.
 *
 * COMPLETE FOR THE STATISTICS IS NARROWER THAN COMPLETE. An activity completed by automation, or one flagged as
 * complete while its status says it is still waiting, is deliberately left out of these counts: legacy refines
 * the flag with the status before it uses it, and it uses the refined answer in three places of an add - here,
 * negated, for the aggregate of the uncompleted activities, and for the queue of the reminders.
 * {@see CompletionForStatistics} is that one answer, asked and never derived a second time.
 *
 * THE SET OF BINDINGS IS THE EFFECTIVE ONE the write of the bindings left in the model, not the one the caller
 * gave.
 *
 * @internal
 */
final class RegisterEntityStatistics implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'RegisterEntityStatistics';
	}

	/**
	 * The outer gate of legacy: complete for the statistics, and a deadline given at all - the sentinel a run
	 * re-read from storage included, a deadline the run was forced not to have excluded.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (!CompletionForStatistics::isComplete($context->getActivity()))
		{
			return false;
		}

		return DeadlineSentinelGuard::isGivenToTheRun($context);
	}

	public function execute(PipelineContext $context): Result
	{
		$deadline = DeadlineSentinelGuard::readFrom($context->getTransit());
		if ($deadline === null)
		{
			// The sentinel of "no deadline": the inner gate of legacy refuses every binding under it.
			return new Result();
		}

		EntityStatisticsOfADay::reAggregate($context->getActivity()->getBindings() ?? [], $deadline);

		return new Result();
	}

	/**
	 * An activity whose statistics could not be re-aggregated is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
