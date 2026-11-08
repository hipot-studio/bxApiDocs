<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\V2\Internal\Access\Activity\CallDeletionRestrictionRule;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Refuses to remove the record of a telephone conversation from anybody who administers none of the objects it is
 * bound to: row 3 of the owner table STEP-DELETE (crm_activity.php:1276-1288).
 *
 * IT IS THE ONE CHECK OF RIGHTS IN ALL THREE WRITE PIPELINES, AND IT IS NOT A RIGHT. Legacy checks nothing on an
 * add and nothing on an update; the rights over the bound objects belong to the caller, and the pipeline asks this
 * one restriction of the domain and nothing else. So the step asks {@see CallDeletionRestrictionRule} directly
 * rather than the whole verdict of a deletion: the general check would add the rights over the bindings, which
 * legacy never asked for here, and refuse deletions the portal performs today. The hidden check of the right to
 * READ the activity - the one legacy performs by loading the row with permissions on - belongs to the facade over
 * the legacy entry point and not to any step.
 *
 * THE ROW OF THE OWNER TABLE IS GATED BY WHAT THE CALLER ASKED FOR. Legacy takes the switch as a positional
 * argument of its entry point and compares it strictly with true, so a caller that passed false skips the whole
 * check even while acting as a real user - and a dozen callers in the module do exactly that. The gate is
 * therefore the switch and nothing derived from the user
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::shouldCheckPermissions()}).
 *
 * THE SYSTEM IS EXEMPT, AND THE RULE SAYS SO RATHER THAN THE STEP. An operation acting as nobody - a user of zero,
 * which is what emptying the recycle bin and cleaning up volume act as - is never restricted, and that is a
 * property of the restriction itself. The step asks with the effective user of the operation, which is the value
 * legacy computes here out of its option array and its fallback (:1279-1281), and the projection of the options
 * has already computed.
 *
 * @internal
 */
final class CheckCallDeletionRestriction implements PipelineStepInterface
{
	/**
	 * The user may not delete this record of a call. The message is the one legacy registers word for word, so a
	 * caller reading the error of the facade sees what it sees today.
	 */
	public const ERROR_ACCESS_DENIED = 'CRM_ACTIVITY_ACCESS_DENIED';

	private readonly CallDeletionRestrictionRule $restriction;

	/**
	 * @param CallDeletionRestrictionRule|null $restriction the restriction of the domain; the one seam of the step.
	 */
	public function __construct(?CallDeletionRestrictionRule $restriction = null)
	{
		$this->restriction = $restriction ?? new CallDeletionRestrictionRule();
	}

	public function getName(): string
	{
		return 'CheckCallDeletionRestriction';
	}

	/**
	 * Only an operation that asked for the check gets it - see the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getOperationContext()->shouldCheckPermissions();
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$userId = $context->getOperationContext()->getCurrentUserId();
		if ($this->restriction->isDeletionRestricted($userId, $context->getActivityId()))
		{
			return $result->addError(new Error('Access denied.', self::ERROR_ACCESS_DENIED));
		}

		return $result;
	}

	/**
	 * A refusal ends the operation and leaves the activity untouched, which is what legacy does with its false.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}
}
