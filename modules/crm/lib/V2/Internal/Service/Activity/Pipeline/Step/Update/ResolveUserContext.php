<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Settles which user this update acts as: step 2 of the owner table of an update (crm_activity.php:495-497,
 * LBS-1 section 3.2).
 *
 * THE PAIR IS TWO VALUES AND THEY ARE NOT INTERCHANGEABLE (item D2). Legacy keeps the effective user - what the
 * caller named, or the current user of the portal where the caller named nobody - and the raw one, which is what
 * the caller actually named, zero included. The effective value is what the check of the fields, the editor of the
 * activity and the author of the record of the timeline are taken from; the raw one is what picks who performs an
 * associated task, because the chain of that choice tells "nobody was named" from "the current user was named".
 * Legacy writes the effective value back into its own option array right here, so that everything below it reads
 * one answer and not the formula.
 *
 * IN V2 THE PAIR IS SETTLED AT THE PROJECTION OF THE OPTIONS AND NOWHERE ELSE, so there is nothing left for this
 * step to derive - and deriving it again would be wrong rather than redundant. The projection applies exactly the
 * fallback legacy applies, and it applies it where legacy applies it: on an absent key. Once the operation is a
 * typed context there is no absent key any more, only a value; a fallback re-applied here would replace an
 * explicitly named "nobody" - the system caller of a mass operation, which legacy leaves at zero - with whoever
 * happens to be logged in, and would do it on the one operation that has this step.
 *
 * WHAT THE STEP DOES OWN IS THE INVARIANT LEGACY GOT FOR FREE. Both halves come out of one key there, so they
 * cannot disagree: a raw value that names somebody is always the effective one as well. A context assembled by
 * hand - which is how a native caller of the service builds one - can disagree, and then the provider hooks of the
 * update act as one user while the row and the timeline are written as another. That is refused here, before the
 * fields of the activity are checked and before anything is written. The refusal does not interrupt the operation,
 * because legacy interrupts on nothing of the kind: it is logged as the failure of a step, and the update goes on
 * with the effective value, which is the value every consumer below reads anyway.
 *
 * @internal
 */
final class ResolveUserContext implements PipelineStepInterface
{
	/**
	 * The two halves of the pair of the user disagree: the caller named one user and the operation acts as
	 * another.
	 */
	public const ERROR_USER_NOT_RESOLVED = 'CRM_ACTIVITY_ACTING_USER_NOT_RESOLVED';

	public function getName(): string
	{
		return 'ResolveUserContext';
	}

	/**
	 * Every update acts as somebody. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();
		$operationContext = $context->getOperationContext();

		if (!self::isOnePair($operationContext))
		{
			return $result->addError(new Error(
				'The operation names one user and acts as another: '
				. $operationContext->getRawCurrentUser() . ' against ' . $operationContext->getCurrentUserId(),
				self::ERROR_USER_NOT_RESOLVED,
			));
		}

		return $result;
	}

	/**
	 * Legacy has no failure here, so neither has this.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Whether the two halves are the two halves of one answer. A raw value of zero says the caller named nobody,
	 * and then the effective value is the fallback and may be anybody - including nobody, on a portal with no
	 * user logged in.
	 */
	private static function isOnePair(ActivityOperationContext $operationContext): bool
	{
		$suppliedUserId = $operationContext->getRawCurrentUser();

		return $suppliedUserId <= 0 || $suppliedUserId === $operationContext->getCurrentUserId();
	}
}
