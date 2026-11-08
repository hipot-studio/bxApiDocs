<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityBindingRepository;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Takes the snapshot of what the activity is bound to before this update writes anything: step 4 of the owner
 * table of an update (crm_activity.php:511).
 *
 * THE POSITION IS THE POINT OF THE STEP (order invariant 4 of LBS-1, section 13). The bindings of an activity are
 * rewritten by a step far below this one, and four effects of the update need the set as it stood before that: the
 * difference that decides whether the rows are rewritten at all, the statistics of the deal or the lead the
 * activity used to be bound to, the badges of a change of bindings, and the record of the timeline about a move.
 * Reading the set after the write would answer every one of those four with the set that has just been written -
 * silently, and with no test of a single step able to notice.
 *
 * IT IS A SNAPSHOT OF THE PREVIOUS STATE AND IT IS KEPT WITH IT. The bindings of an activity are a field of its
 * model, so the set goes into the model of the state the step above loaded, and every step below asks that model
 * for them. Nothing else would be the truth: a carrier of its own would make "the previous state" a state whose
 * bindings live elsewhere, and the pair of the change set cannot hold it yet - a pair needs both halves at once and
 * the current set is not known until the row has been re-read.
 *
 * They are written through the hydration path, as the value read from storage that they are. The set is put there
 * even when it is empty: an activity bound to nothing is a state legacy rows are in, and "nobody has taken the
 * snapshot yet" has to stay a different answer from "there was nothing to take".
 *
 * @internal
 */
final class SnapshotPreviousBindings implements PipelineStepInterface
{
	/**
	 * The run reached this step without the state the activity was in, which only happens when the step reading
	 * that state is missing from the declaration or has failed.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	/**
	 * The run reached this step with no activity to update.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private readonly ActivityBindingRepository $repository;

	/**
	 * @param ActivityBindingRepository|null $repository the storage boundary of the bindings; the one seam of
	 *        the step.
	 */
	public function __construct(?ActivityBindingRepository $repository = null)
	{
		$this->repository = $repository ?? new ActivityBindingRepository();
	}

	public function getName(): string
	{
		return 'SnapshotPreviousBindings';
	}

	/**
	 * Every update takes the snapshot. Legacy reads the set unconditionally, and the steps that compare against
	 * it have their own conditions.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		if (!$context->hasPreviousState())
		{
			return $result->addError(new Error(
				'The previous state of the activity was not loaded.',
				self::ERROR_PREVIOUS_STATE_NOT_LOADED,
			));
		}

		$context->getPreviousActivity()->internalSet(
			Activity::bindings,
			$this->repository->getByActivityId($activityId),
		);

		return $result;
	}

	/**
	 * Legacy interrupts an update on the state it loads, on the check of the fields and on the re-read of the
	 * row - and on nothing else.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}
}
