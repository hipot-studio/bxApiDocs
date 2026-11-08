<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Pipeline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\ThrowableTransparentStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Writes the completion by running the update of the activity: row 4 of the owner table STEP-COMPLETE
 * (crm_activity.php:4407), and every effect of a completion is an effect of that update.
 *
 * A COMPLETE IS A SPECIALIZATION OF AN UPDATE AND HAS NO WRITE OF ITS OWN. Legacy ends its completion with a call
 * of its own update, which is what recomputes the counters, tells the timeline, notifies the responsible,
 * publishes the events and calls the hooks - so a complete owning any of that would own it twice. What travels
 * into the update is the change and nothing else: the completion flag, and the status beside it only where the
 * operation named one.
 *
 * THE ENTRY POINT OF THE SERVICE IS WHAT IS CALLED, NOT THE DECLARATION OF THE UPDATE. Assembling the pipeline of
 * an update here would be a second entry into that operation, with the contract of the first one - the check of
 * the identifier, the identifier put onto the model - copied or lost. The one instance of the service is taken
 * from the container of the module, lazily and inside the run: the declarations attached there are closures, so
 * nothing about a complete constructs the steps of an update until this row really runs.
 *
 * THE STATUS OF THE COMPLETION IS NOT DECIDED HERE (item B3). It is passed on where the operation named it - the
 * automatic completion is the one caller that does, and :4419 is where legacy names it - and left out entirely
 * where it did not. Deriving it would be a second copy of the rule of the check of the fields of an update, and
 * the copy that overwrites an earlier automatic completion with a plain one.
 *
 * A FAILING UPDATE IS A FAILING COMPLETE, which is why the step aborts: legacy answers with the result of its
 * update untouched, so an update refused above its write leaves the completion refused too. The state the update
 * read back becomes the state of the run, so a complete answers with the stored activity exactly as the other
 * three operations do.
 *
 * @internal
 */
final class RunUpdatePipeline implements ThrowableTransparentStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without a completion to write, which the entry point of the service always names.
	 * An update told nothing about the flag would touch neither the flag nor the status.
	 */
	public const ERROR_COMPLETION_NOT_NAMED = 'CRM_ACTIVITY_COMPLETION_NOT_NAMED';

	private readonly ?ActivityLifecycleService $lifecycleService;

	/**
	 * @param ActivityLifecycleService|null $lifecycleService the operations over an activity, the update among
	 *        them; the service of the hit when the caller names none.
	 */
	public function __construct(?ActivityLifecycleService $lifecycleService = null)
	{
		$this->lifecycleService = $lifecycleService;
	}

	public function getName(): string
	{
		return 'RunUpdatePipeline';
	}

	/**
	 * Every complete that got this far writes. Legacy has no path around this.
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

		$isCompleted = $context->getActivity()->isCompleted();
		if ($isCompleted === null)
		{
			return $result->addError(new Error(
				'The completion of the activity was not named.',
				self::ERROR_COMPLETION_NOT_NAMED,
			));
		}

		$updateResult = $this->lifecycleService()->update(
			$activityId,
			self::changeOf($context, $isCompleted),
			$context->getOperationContext(),
		);
		self::carryOverWhetherTheFieldsWereChecked($updateResult, $context);

		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		$stored = $updateResult->getData()[Pipeline::RESULT_ACTIVITY] ?? null;
		if ($stored instanceof Activity)
		{
			$context->setActivity($stored);
		}

		return $result;
	}

	/**
	 * An update the completion could not be written through leaves the activity as it was.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	/**
	 * A COMPLETION EMPTIES THE ACCUMULATOR OF ERRORS OF THE FACADE THROUGH THE UPDATE IT PERFORMS AND IN NO OTHER
	 * WAY, exactly as legacy does - the check of the fields of that update is the only check a completion runs, and
	 * a completion refused above it (an activity that is not there, an activity already in the state asked for)
	 * runs none at all. The fact belongs to the nested run and its reader is the facade, so it is carried over
	 * whichever way the update went ({@see PipelineContext::RUN_VALUE_FIELDS_CHECKED}).
	 */
	private static function carryOverWhetherTheFieldsWereChecked(Result $updateResult, PipelineContext $context): void
	{
		$nested = $updateResult->getData()[Pipeline::RESULT_CONTEXT] ?? null;
		if (
			$nested instanceof PipelineContext
			&& $nested->hasRunValue(PipelineContext::RUN_VALUE_FIELDS_CHECKED)
		)
		{
			$context->setRunValue(PipelineContext::RUN_VALUE_FIELDS_CHECKED, true);
		}
	}

	/**
	 * The change the update is handed: the completion flag, plus the status where the operation named one - see the
	 * note of the class. A model of its own rather than the one of the run, so that the normalization of the update
	 * works on the change and not on what the caller of the complete still holds.
	 */
	private static function changeOf(PipelineContext $context, bool $isCompleted): Activity
	{
		$change = (new Activity())->setCompleted($isCompleted);

		$named = $context->getActivity();
		$status = $named->getCompletionStatus();
		if ($named->isFieldChanged(Activity::completionStatus) && $status instanceof CompletionStatus)
		{
			$change->setCompletionStatus($status);
		}

		return $change;
	}

	private function lifecycleService(): ActivityLifecycleService
	{
		return $this->lifecycleService ?? Container::getInstance()->getActivityLifecycleService();
	}
}
