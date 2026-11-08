<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Continues the chain of the activity its parent belongs to: step 5 of the owner table of an add
 * (crm_activity.php:127-144), checklist item C3.
 *
 * A CHAIN IS ONE VALUE FOR EVERY ACTIVITY IN IT. The chain of an answer to a letter is the chain of the letter
 * it answers, not the letter itself, so what is inherited is the chain of the parent and never its identifier -
 * that is what keeps a thread of three letters one thread. An activity of no parent starts a chain of its own,
 * and the step doing that runs after the insert (step 12 of the owner table), because a chain of one activity
 * is named after an identifier the storage has not handed out yet.
 *
 * A PARENT THAT IS NOT THERE IS NOT AN ERROR, IT IS NO PARENT. Legacy reads the parent by its identifier and,
 * finding nothing, resets both the parent and the chain to zero (:141-145) - the activity is stored as one
 * without a parent, and nobody is told. Taking a parent away from the model is the one place the pipeline
 * writes a field the model calls read-only-by-contract: the model refuses a second parent on purpose (a silent
 * refusal of legacy the facade reproduces), so the value is dropped through the hydration path rather than
 * through the setter that would throw.
 *
 * WHY IT ASKS THE REPOSITORY FOR ONE VALUE. The chain is a storage column and no field of the typed model, and
 * one answer holds both facts this step needs: whether the parent exists at all, and which chain it continues
 * ({@see ActivityRepositoryInterface::getThreadId()}). A chain of zero is a value stored rows of legacy really
 * have, and it is not the same answer as "there is no such activity".
 *
 * @internal
 */
final class ResolveThread implements PipelineStepInterface
{
	private const COLUMN_THREAD_ID = 'THREAD_ID';

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities; the one seam
	 *        of the step.
	 */
	public function __construct(?ActivityRepositoryInterface $repository = null)
	{
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'ResolveThread';
	}

	/**
	 * Only where the caller named a parent to inherit a chain from.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return self::parentOf($context) > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();
		$threadId = $this->repository->getThreadId(self::parentOf($context));

		if ($threadId === null)
		{
			$activity->internalSet(Activity::parentActivityId, null);
			$context->getTransit()->set(self::COLUMN_THREAD_ID, 0);

			return new Result();
		}

		$context->getTransit()->set(self::COLUMN_THREAD_ID, $threadId);

		return new Result();
	}

	/**
	 * Legacy interrupts nothing over a parent it could not find: the activity is stored without one.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private static function parentOf(PipelineContext $context): int
	{
		return (int)$context->getActivity()->getParentActivityId();
	}
}
