<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Reads the state the activity is in before anything of this update is written: step 1 of the owner table of an
 * update (crm_activity.php:488-493), the first of the three steps an update is interrupted on.
 *
 * THE WHOLE UPDATE RESTS ON THIS ONE READ. Every condition of an update is a difference between two states, and
 * this is where the first of the two comes from: the provider is resolved from it, the status of a completion
 * follows it, the statistics of the deadline it used to have are unregistered by it, and the record of the
 * timeline reports it as the fields the activity had before. So the step declares itself aborting - legacy
 * answers a missing row with a plain false before it has done anything at all, and an update of an activity that
 * is not there is not an operation that partly succeeds.
 *
 * THE ROW IS READ WITHOUT ASKING WHETHER IT MAY BE READ. Legacy reads it with the check of permissions switched
 * off, whatever the caller asked for: an update checks the right to update, and the state before it is a value of
 * that operation rather than an answer to a reader. The perm-aware reading of an activity belongs to the facade.
 *
 * THE SUBSTITUTED LOAD IS NOT CONSULTED HERE, AND THAT IS THE FIDELITY. A caller may hand a row over instead of
 * having it read ({@see \Bitrix\Crm\V2\Internal\Service\Activity\ActivityOperationContext::getActualItem()}), and
 * exactly one legacy pipeline honours that - the delete, at :1268 and :1546. The update ignores it and reads the
 * row itself, so honouring it here would let the mass operations of the portal update an activity against a state
 * they read at some earlier moment, which is a behaviour nobody has today. The legacy shape of that value is
 * therefore left alone by this step; the triple it needs comes off the storage boundary, which speaks in the typed
 * values of V2.
 *
 * THE BINDINGS OF THE PREVIOUS STATE ARE NOT READ HERE EITHER. They live in a table of their own and legacy reads
 * them two steps later, after the check of the fields has had its say (:511); the step of that row writes them
 * into the model of the state this one loaded.
 *
 * @internal
 */
final class LoadPreviousState implements PipelineStepInterface
{
	/**
	 * There is no activity with that id. The message is the comment legacy answers its false with.
	 */
	public const ERROR_NOT_FOUND = 'CRM_ACTIVITY_NOT_FOUND';

	/**
	 * The run reached this step with no activity to update, which the entry point of the service refuses to
	 * build. It is a mistake of whoever assembled the run rather than of the caller.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

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
		return 'LoadPreviousState';
	}

	/**
	 * Every update starts from the state before it. Legacy has no path around this.
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

		$state = $this->repository->getStateById($activityId);
		if ($state === null)
		{
			return $result->addError(new Error(
				"Could not find CrmActivity(ID = {$activityId})",
				self::ERROR_NOT_FOUND,
			));
		}

		$context->setPreviousState(
			$state[ActivityRepositoryInterface::STATE_ACTIVITY],
			$state[ActivityRepositoryInterface::STATE_PAYLOAD],
			$state[ActivityRepositoryInterface::STATE_TRANSIT],
		);

		return $result;
	}

	/**
	 * An update of an activity that is not there does nothing at all.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}
}
