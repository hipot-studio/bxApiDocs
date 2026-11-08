<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete;

use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Ends the operation where the activity is already in the state asked for: row 2 of the owner table STEP-COMPLETE
 * (crm_activity.php:4392-4395).
 *
 * IT IS A SUCCESS AND NOT A FAILURE (item H1). Legacy answers a plain true and performs nothing at all - no hook
 * of the provider, no update, so no row is written, no event is published and no counter is recomputed. The
 * pipeline says that with a success that stops the run ({@see PipelineStepInterface::STOP_WITH_SUCCESS}), which
 * is the second use of that outcome in the design - the first being the transfer of a delete to the recycle bin.
 * A refusal here would turn "there is nothing to do" into an error the callers of a mass completion would have to
 * filter out.
 *
 * THE COMPARISON IS BETWEEN THE STORED FLAG AND THE FLAG THE OPERATION WANTS. Both are the completion of a model:
 * the one row 1 read, and the one the entry point of the service put into the state of the run. An activity whose
 * stored flag is unknown - which no row written by any pipeline has - is not the same answer as one already in
 * the state, exactly as `isset($fields['COMPLETED'])` of legacy is not the same as its comparison.
 *
 * @internal
 */
final class SkipIfAlreadyInState implements PipelineStepInterface
{
	public function getName(): string
	{
		return 'SkipIfAlreadyInState';
	}

	/**
	 * The stored completion is the one asked for - see the note of the class on the pair being compared. A run
	 * that has read no state cannot answer the question and lets the operation carry on.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if (!$context->hasPreviousState())
		{
			return false;
		}

		$stored = $context->getPreviousActivity()->isCompleted();

		return $stored !== null && $stored === $context->getActivity()->isCompleted();
	}

	public function execute(PipelineContext $context): Result
	{
		return (new Result())->setData([PipelineStepInterface::STOP_WITH_SUCCESS => true]);
	}

	/**
	 * The step performs no effect and has nothing to fail at.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
