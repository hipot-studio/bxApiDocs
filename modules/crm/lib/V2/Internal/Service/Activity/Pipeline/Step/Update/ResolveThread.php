<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Attaches an activity to the parent it did not have, and refuses to move one that already has a parent: row 8 of
 * the owner table of an update (crm_activity.php:597-613).
 *
 * A PARENT IS GIVEN ONCE. That is the rule of the domain the model states (the setter of the parent refuses a
 * second one) and the rule legacy enforces here, silently: it looks the parent up only for an activity whose
 * stored parent is zero (:598), and everything it did not look up falls into the branch that drops both the parent
 * and the chain from the fields being written (:611-614). So an update that would move an activity onto another
 * parent changes nothing at all in legacy, and nobody learns why. In V2 the attempt is reported as an error of the
 * result while the effect stays exactly the same - the facade keeps the silence towards its own callers, so the
 * behaviour of the product does not change and a native caller finds out what it asked for.
 *
 * A PARENT THAT IS NOT THERE IS NOT AN ERROR, IT IS NO PARENT, and here that is the same branch: an activity whose
 * parent could not be read is written as one whose parent nobody touched.
 *
 * THE REFUSAL IS SPELLED AS "PUT BACK WHAT THE ROW HAS" RATHER THAN AS "FORGET WHAT THE CALLER SAID". Legacy drops
 * its two keys, and dropping them means the write leaves both columns as they are; the model of V2 has no way to
 * unmark a field the caller assigned, so a parent taken away would be written as a zero and would detach an
 * activity from the parent it has. Writing the parent of the stored row instead writes the value that is already
 * there, which is what legacy leaves behind, and it says the same thing to the event of row 11 - the model of a
 * refused attempt carries no parent at all when the stored row has none.
 *
 * IT IS A DIFFERENT STEP FROM THE ONE OF AN ADD. The step of an add attaches a new activity to a chain and answers
 * a missing parent with a chain of zero (:143-145), because the columns of a new row have to be written; here
 * neither column may be written at all. There is no second half either - an update never starts a chain of its
 * own, the activity is already in one.
 *
 * WHY IT ASKS THE REPOSITORY FOR ONE VALUE. The chain is a storage column and no field of the typed model, and one
 * answer holds both facts this step needs: whether the parent exists at all, and which chain it continues
 * ({@see ActivityRepositoryInterface::getThreadId()}). A chain of zero is a value stored rows of legacy really
 * have, and it is not the same answer as "there is no such activity".
 *
 * @internal
 */
final class ResolveThread implements PipelineStepInterface
{
	/**
	 * The operation names a parent for an activity that has one. Legacy refuses the same change without a word;
	 * the effect here is that refusal, and the error is what V2 adds to it.
	 */
	public const ERROR_PARENT_ALREADY_SET = 'CRM_ACTIVITY_PARENT_ALREADY_SET';

	/**
	 * The run reached this step without the state the activity was in, which only happens when the step reading
	 * that state is missing from the declaration or has failed. Whether the activity may be attached at all is a
	 * question about that state, so there is nothing to decide without it.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

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
		return 'ResolveThread';
	}

	/**
	 * Only where the operation names a parent. An update that says nothing about one has nothing to attach and
	 * nothing to refuse - the branch of legacy that drops the two keys leaves nothing behind for such a run, the
	 * chain being a column no caller of V2 can hand over.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return self::parentOf($context) > 0;
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

		$previousParentId = (int)($context->getPreviousActivity()->getParentActivityId() ?? 0);
		if ($previousParentId > 0)
		{
			$this->keepTheParentOfTheStoredRow($context);

			return $result->addError(new Error(
				"The activity is already attached to #{$previousParentId} and a parent is given once.",
				self::ERROR_PARENT_ALREADY_SET,
			));
		}

		$threadId = $this->repository->getThreadId(self::parentOf($context));
		if ($threadId === null)
		{
			$this->keepTheParentOfTheStoredRow($context);

			return $result;
		}

		$context->getTransit()->set(self::COLUMN_THREAD_ID, $threadId);

		return $result;
	}

	/**
	 * Legacy interrupts nothing over a parent: an activity it refused to move is written with everything else the
	 * operation asked for.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * Leaves the two columns of the chain as the stored row has them. The parent goes back through the hydration
	 * path: it is the value the row already carries, so this is the undoing of an assignment and not a change
	 * anybody asked for.
	 */
	private function keepTheParentOfTheStoredRow(PipelineContext $context): void
	{
		$context->getActivity()->internalSet(
			Activity::parentActivityId,
			$context->getPreviousActivity()->getParentActivityId(),
		);
		$context->getTransit()->remove(self::COLUMN_THREAD_ID);
	}

	private static function parentOf(PipelineContext $context): int
	{
		return (int)($context->getActivity()->getParentActivityId() ?? 0);
	}
}
