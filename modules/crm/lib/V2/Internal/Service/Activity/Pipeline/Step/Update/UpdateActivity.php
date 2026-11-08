<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Access\Activity\ActivityAccess;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Writes the row of the activity: row 12 of the owner table of an update (crm_activity.php:655-669).
 *
 * THE STEP THAT DIVIDES THE PIPELINE IN TWO, AND IT INTERRUPTS NOTHING. Everything above it prepares the values of
 * one row; everything below it works on an activity that has been written, and reads it back for itself at row 17.
 * Legacy fires its statement and never looks at what came of it (:661-670): the update goes on through all of its
 * effects whatever the storage answered. So this step does not abort - a failed write is discovered where legacy
 * discovers it, at the re-read of the row, which is one of the three steps an update does interrupt on. Making it
 * abort would be the safer design and the wrong one: it would turn a class of updates that today complete their
 * side effects into ones that stop halfway, and no test of the characterization would notice.
 *
 * A REFUSAL IS STILL AN ERROR OF THE DOMAIN. "Not checked" means "does not end the operation" and not "goes
 * unrecorded": the storage boundary of V2 answers a failed write with an exception, and letting it out would have
 * the engine log a throwable of an anonymous step. It is turned into a named error instead, with the technical
 * reason in the custom data - visible to whoever reports the failure, out of the way of whoever shows it to a user
 * - and the engine logs the failure of a step that carries on.
 *
 * WHAT IS WRITTEN IS WHAT THE STEPS ABOVE LEFT. Only the fields the operation marked as changed, plus the storage
 * columns of the run and the serialized values of the payload; the columns legacy refuses to change on an update
 * are dropped by the boundary itself. The texts of the caller are NOT put back afterwards, and that is the one
 * place this step differs from the insert of an add: an update deliberately keeps the escaped emoji, both in the
 * column and in everything the steps below it hand over (row D14 of the registry of intentional deviations).
 *
 * AND THE ROW THAT WAS WRITTEN IS FORGOTTEN BY THE PERMISSION MODEL (open question Q-4). Reading an activity is
 * granted to the person responsible for it, so a column of this very row is an input of a decision about rights,
 * and an activity that changed hands within a hit would otherwise keep the decisions taken while it was on
 * somebody else - as would the memory of the row those decisions were taken from. The step saving the bindings
 * says the same thing about the other input of a decision; this is the write boundary saying it about this one,
 * and it is the earliest point after which the row is no longer what anybody remembered. Whether a column that
 * matters actually moved is not asked: the write is what makes the memory of the row stale, and the invalidation
 * costs a hit-local cache entry rather than a query. The model is asked for through the container and never
 * built here - a model of its own would forget only its own decisions, which nobody else asks.
 *
 * @internal
 */
final class UpdateActivity implements PipelineStepInterface
{
	/**
	 * The row could not be written. The reason travels in the custom data under {@see self::ERROR_DETAIL_REASON}.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_NOT_STORED';

	public const ERROR_DETAIL_REASON = 'reason';

	/**
	 * The run reached this step with no activity to update, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

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
		return 'UpdateActivity';
	}

	/**
	 * Every update writes its row. Legacy has no path around this - an operation that changed nothing still runs
	 * the statement, and the boundary is the one deciding there is nothing to write.
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

		try
		{
			$this->repository->update($context->getActivity(), $context->getPayload(), $context->getTransit());
		}
		catch (Throwable $error)
		{
			return $result->addError(new Error(
				'DB connection was lost.',
				self::ERROR_NOT_STORED,
				[self::ERROR_DETAIL_REASON => $error->getMessage()],
			));
		}

		$this->forgetWhatWasDecidedAboutTheActivity($activityId);

		return $result;
	}

	/**
	 * Legacy does not check what came of its write. See the note of the class.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Drops every decision of this hit about the activity, together with the bindings and the row those
	 * decisions were derived from (open question Q-4). See the note of the class.
	 *
	 * The access model of the module, named rather than reached for through a getter alone: the guard of the
	 * rollout of the new rights ([P3.T6]) lists the files referring to it by name.
	 */
	private function forgetWhatWasDecidedAboutTheActivity(int $activityId): void
	{
		$this->accessModel()->forgetActivity($activityId);
	}

	private function accessModel(): ActivityAccess
	{
		return Container::getInstance()->getActivityAccess();
	}
}
