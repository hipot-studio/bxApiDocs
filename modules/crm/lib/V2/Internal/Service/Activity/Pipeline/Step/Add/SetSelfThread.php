<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Result;

/**
 * Starts the chain of an activity that continues none: step 12 of the owner table of an add
 * (crm_activity.php:219-225).
 *
 * A CHAIN OF ONE ACTIVITY IS NAMED AFTER IT. The chain of an answer to a letter is the chain of the letter it
 * answers, and the step above the write inherits it ({@see ResolveThread}); a letter nobody answered is the head
 * of a chain of its own, and the name of that chain is its own identifier. Which is why this is a step of its
 * own, standing below the insert: the identifier the value is made of does not exist above it.
 *
 * A SECOND WRITE OF ONE COLUMN, AS IN LEGACY. Nothing else is touched and no audit stamp is bumped - the write
 * of the activity has already happened - so the storage layer is asked for exactly that
 * ({@see ActivityRepositoryInterface::startOwnThread()}) instead of the update path, which would bump the stamp
 * of the last change of an activity that just appeared.
 *
 * @internal
 */
final class SetSelfThread implements PipelineStepInterface
{
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
		return 'SetSelfThread';
	}

	/**
	 * An activity that continues no chain, and one the storage has already named. A parent the step above could
	 * not find is no parent at all, so an activity whose parent turned out missing starts a chain of its own too
	 * - which is what legacy does by resetting the pair to zero (:148-152).
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		if ((int)$context->getActivity()->getParentActivityId() !== 0)
		{
			return false;
		}

		return $context->getActivityId() > 0;
	}

	public function execute(PipelineContext $context): Result
	{
		$this->repository->startOwnThread($context->getActivityId());

		return new Result();
	}

	/**
	 * An activity whose chain could not be written is stored all the same: legacy checks the answer of that
	 * query nowhere.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
