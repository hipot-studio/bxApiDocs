<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\Enum\CompletionStatus;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Pipeline;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\Result;
use Closure;

/**
 * The lifecycle of an activity: one entry point per operation, and every operation a declared pipeline of named
 * steps.
 *
 * WHAT AN ENTRY POINT DOES AND WHAT IT DOES NOT. It builds the state of one run - the activity of the operation,
 * the representation the caller gave, the working representation derived from it - and hands it to the pipeline
 * of that operation. It performs no effect of its own: every effect of legacy is a step, so that the order of the
 * effects lives in one readable declaration instead of being spread over the body of a method.
 *
 * RIGHTS ARE NOT CHECKED HERE, and that is parity rather than an omission: legacy checks none on an add and on an
 * update either, the rights over the bound objects belong to the caller, and the one check its delete does have -
 * the restriction on removing the record of a call - is a step of the delete pipeline. The hidden read check of
 * the legacy delete belongs to the facade.
 *
 * HOW THE PIPELINES ARRIVE. Each operation takes a factory building its pipeline, lazily, so that a call of one
 * operation does not construct the steps of the other three. A factory that has not been supplied is a service
 * assembled without that operation - asking for it fails loudly instead of reporting a success no effect stands
 * behind. Every factory is expected to name its pipeline after the operation, and a mismatch is refused: the
 * names end up in the log records of failing steps, so they may not drift apart from the operations.
 *
 * @internal
 */
final class ActivityLifecycleService
{
	public const OPERATION_ADD = 'add';

	public const OPERATION_UPDATE = 'update';

	public const OPERATION_DELETE = 'delete';

	public const OPERATION_COMPLETE = 'complete';

	/**
	 * @param Closure|null $addPipelineFactory `function (): Pipeline` - the declaration of the add.
	 * @param Closure|null $updatePipelineFactory likewise for the update.
	 * @param Closure|null $deletePipelineFactory likewise for the delete.
	 * @param Closure|null $completePipelineFactory likewise for the complete.
	 */
	public function __construct(
		private readonly ?Closure $addPipelineFactory = null,
		private readonly ?Closure $updatePipelineFactory = null,
		private readonly ?Closure $deletePipelineFactory = null,
		private readonly ?Closure $completePipelineFactory = null,
	)
	{
	}

	/**
	 * Creates the given activity.
	 *
	 * @param Activity $activity the values of the caller; the pipeline normalizes and completes them.
	 * @return Result the stored activity under {@see Pipeline::RESULT_ACTIVITY} on success.
	 */
	public function add(Activity $activity, ActivityOperationContext $operationContext): Result
	{
		return $this->run(
			$this->addPipelineFactory,
			self::OPERATION_ADD,
			new PipelineContext($activity, $operationContext),
		);
	}

	/**
	 * Applies the changed values of the given activity to the stored one.
	 *
	 * @param Activity $activity only the fields the caller touched are written; the id is taken from the argument
	 *        beside it, and a model already carrying a different one is a programming error.
	 * @throws ArgumentException on a non-positive id, or on a model belonging to another activity.
	 */
	public function update(int $activityId, Activity $activity, ActivityOperationContext $operationContext): Result
	{
		self::assertId($activityId);

		$carriedId = $activity->getId();
		if ($carriedId !== null && $carriedId !== $activityId)
		{
			throw new ArgumentException(
				"The activity to update is {$activityId}, the given model is {$carriedId}",
				'activity',
			);
		}

		$activity->internalSet(Activity::id, $activityId);

		return $this->run(
			$this->updatePipelineFactory,
			self::OPERATION_UPDATE,
			new PipelineContext($activity, $operationContext),
		);
	}

	/**
	 * Removes the activity, or moves it to the recycle bin when the pipeline decides so.
	 *
	 * The activity is not read here: the step of the delete that loads it also honours the state a system caller
	 * may have supplied instead, which is the one way of bypassing the read check.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function delete(int $activityId, ActivityOperationContext $operationContext): Result
	{
		self::assertId($activityId);

		return $this->run(
			$this->deletePipelineFactory,
			self::OPERATION_DELETE,
			new PipelineContext(self::activityOf($activityId), $operationContext),
		);
	}

	/**
	 * Completes the activity, or reopens it.
	 *
	 * The status of the completion is not assigned here and is normally not assigned by the caller either: the
	 * validation of the update derives it from the previous state, which is what keeps an automatically completed
	 * activity automatically completed. The one caller naming it is the automatic completion itself.
	 *
	 * @throws ArgumentException on a non-positive id.
	 */
	public function complete(
		int $activityId,
		bool $isCompleted,
		ActivityOperationContext $operationContext,
		?CompletionStatus $completionStatus = null,
	): Result
	{
		self::assertId($activityId);

		$activity = self::activityOf($activityId)->setCompleted($isCompleted);
		if ($completionStatus !== null)
		{
			$activity->setCompletionStatus($completionStatus);
		}

		return $this->run(
			$this->completePipelineFactory,
			self::OPERATION_COMPLETE,
			new PipelineContext($activity, $operationContext),
		);
	}

	/**
	 * @throws NotImplementedException when the service was assembled without that operation.
	 * @throws ArgumentException when the factory builds no pipeline, or one named after another operation.
	 */
	private function run(?Closure $pipelineFactory, string $operation, PipelineContext $context): Result
	{
		if ($pipelineFactory === null)
		{
			throw new NotImplementedException("The {$operation} pipeline of an activity is not declared");
		}

		$pipeline = $pipelineFactory();
		if (!$pipeline instanceof Pipeline)
		{
			throw new ArgumentException(
				"The factory of the {$operation} of an activity built no pipeline",
				$operation . 'PipelineFactory',
			);
		}

		if ($pipeline->getName() !== $operation)
		{
			throw new ArgumentException(
				"The pipeline of the {$operation} of an activity names itself {$pipeline->getName()}",
				$operation . 'PipelineFactory',
			);
		}

		return $pipeline->run($context);
	}

	/**
	 * The activity an operation over a stored one starts from: its id and nothing else, until a step of the
	 * pipeline reads the rest.
	 */
	private static function activityOf(int $activityId): Activity
	{
		return (new Activity())->internalSet(Activity::id, $activityId);
	}

	/**
	 * @throws ArgumentException
	 */
	private static function assertId(int $activityId): void
	{
		if ($activityId <= 0)
		{
			throw new ArgumentException('The id of an activity has to be positive', 'activityId');
		}
	}
}
