<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityChangeSet;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Answers whether the deadline of the activity moved, and says what a move of it costs: row 24 of the owner table
 * STEP-UPDATE (crm_activity.php:798-805). Unconditional, and it interrupts nothing.
 *
 * THE ROW IS TWO THINGS AT ONCE, as legacy has it. The pair of the deadline is read below as "did the deadline
 * change" - the outer gate of the statistics registered by the previous deadline asks exactly that - and the
 * providers of an activity receive its two values through the projection of the set of changes. Beside the pair
 * stand the two decisions a move forces (:801-804): the queue of the reminders of the activity no longer matches
 * it, and the aggregate of the uncompleted activities has to be recomputed.
 *
 * THE TWO FLAGS FOLLOW THE CHANGE AND NOT THE ROW. Legacy raises them inside its `if`, so an update that moved no
 * deadline says nothing about either; raising them unconditionally would rebuild a queue and an aggregate nothing
 * touched, on every update of the portal. The pair itself is supplied unconditionally - a name nobody supplied
 * reads as "unchanged" and would answer the gates below with a silence they cannot tell from a fact.
 *
 * THE COMPARISON IS THE ONE LEGACY MAKES, over the two states and never over the input. Legacy compares the
 * deadline of the row it read before the write against the deadline of the row it re-read after it, as the strings
 * the column is written in (:799-800); that string comparison is the meaning of {@see ActivityChangeSet::deadline}
 * and belongs to the set rather than to this row. Nothing of the caller can take part: an update strips a deadline
 * of the caller before the provider of the activity is asked (:2128), so a deadline that moved was moved by that
 * provider - or by whoever wrote the row while this operation was running.
 *
 * "NO DEADLINE" IS NULL ON BOTH SIDES. The column says the absence of a deadline with a date of the year 9999, and
 * the storage boundary resolves that sentinel into null on the way out - for the state read before the write and
 * for the state re-read after it alike. So an activity that had none and has none is unchanged, and one that lost
 * or gained a real deadline is changed, which is what legacy answers with its strings. The two answers part company
 * over a row carrying NULL where the sentinel belongs: legacy compares an empty string against the sentinel and
 * calls it a change, V2 has null on both sides. Those rows are the ones a repair agent of the module was written
 * for, and treating both shapes as "no deadline" is a decision of the storage boundary, not of this row.
 *
 * @internal
 */
final class DiffDeadline implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in. There is nothing to compare against.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'DiffDeadline';
	}

	/**
	 * Every update answers the question. What a change of the answer costs is decided inside.
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

		$changes = $context->getChanges();
		$changes->set(
			ActivityChangeSet::deadline,
			$context->getPreviousActivity()->getDeadline(),
			$context->getActivity()->getDeadline(),
		);

		if (!$changes->isChanged(ActivityChangeSet::deadline))
		{
			return $result;
		}

		$context->setRunValue(PipelineContext::RUN_VALUE_PING_QUEUE_NEEDS_SYNC, true);
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
