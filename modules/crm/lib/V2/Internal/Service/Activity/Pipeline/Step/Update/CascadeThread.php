<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Takes the chain of the activity along to the chain of its new parent: row 13 of the owner table of an update
 * (crm_activity.php:673-679), the second write of the row cluster.
 *
 * WHY A SECOND WRITE AT ALL. An activity that had no parent heads a chain of its own, and other activities may
 * already answer to it - the letters of a correspondence point at the first of them. The write of row 12 moved that
 * one activity into the chain of its parent and left everything that followed it behind, pointing at a chain whose
 * head is no longer in it. This statement moves the rest after it, and the row of the activity itself is not part
 * of the set any more: its own column has just been written with the new chain.
 *
 * THE GATE IS THE COLUMN ROW 8 WROTE, and it is the same set of runs legacy performs this for. Legacy asks its
 * fields again - a parent that is given, greater than zero, and absent from the stored row (:674) - but by then
 * those are the fields the step of row 8 has already left behind: the branch that found no parent drops both keys
 * (:611-614), so a chain in the transit means exactly "row 8 resolved one". Asking the same question twice would
 * be a second answer to it, and the two would drift the moment either half of the condition of row 8 moves.
 *
 * IT RUNS OVER AN ACTIVITY WHOSE WRITE MAY HAVE FAILED, exactly as it does in legacy: row 12 does not check what
 * came of its statement and the failure is discovered at row 17. So this step assumes nothing about the row and
 * interrupts nothing either - the worst it can do is move a chain of an activity whose own chain was not stored,
 * which is what legacy does.
 *
 * @internal
 */
final class CascadeThread implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The chain could not be moved. The reason travels in the custom data under {@see self::ERROR_DETAIL_REASON}.
	 */
	public const ERROR_NOT_CASCADED = 'CRM_ACTIVITY_THREAD_NOT_CASCADED';

	public const ERROR_DETAIL_REASON = 'reason';

	private const COLUMN_THREAD_ID = 'THREAD_ID';

	private readonly ActivityRepositoryInterface $repository;

	/**
	 * @param ActivityRepositoryInterface|null $repository the storage boundary of the activities; the one seam of
	 *        the step.
	 */
	public function __construct(?ActivityRepositoryInterface $repository = null)
	{
		$this->repository = $repository ?? new ActivityRepository();
	}

	public function getName(): string
	{
		return 'CascadeThread';
	}

	/**
	 * Only where the chain of the activity changed, which is the one thing the column of the transit says. An
	 * update that named no parent, one whose parent could not be read and one that was refused a second parent
	 * all leave the column behind, and none of them has a chain to take along.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getTransit()->has(self::COLUMN_THREAD_ID);
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$threadId = (int)$context->getTransit()->get(self::COLUMN_THREAD_ID);

		try
		{
			$this->repository->mergeThreadInto($activityId, $threadId);
		}
		catch (Throwable $error)
		{
			return $result->addError(new Error(
				"Could not move the chain of activity #{$activityId} into #{$threadId}.",
				self::ERROR_NOT_CASCADED,
				[self::ERROR_DETAIL_REASON => $error->getMessage()],
			));
		}

		return $result;
	}

	/**
	 * Legacy does not check what came of this statement either, and an update whose chain was not taken along
	 * still performs every effect below it.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
