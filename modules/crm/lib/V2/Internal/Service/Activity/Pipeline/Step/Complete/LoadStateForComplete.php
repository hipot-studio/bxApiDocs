<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Complete;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ProviderPayload;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Reads the state a completion asks two questions of: row 1 of the owner table STEP-COMPLETE
 * (crm_activity.php:4379-4390), the one step a complete is interrupted on.
 *
 * THE READ STATE DOES NOT BECOME THE STATE OF THE RUN, and that is the difference from every other reading step
 * of the design. The model of a complete carries what the operation WANTS - the completion flag it is to reach,
 * and for an automatic completion the status beside it - so replacing the state of the run with the stored row
 * would overwrite the very value rows 2 and 4 exist for. The row therefore arrives as the state the activity was
 * in before this operation ({@see PipelineContext::setPreviousState()}), which is what it is, and the two
 * questions below are asked of the pair.
 *
 * IT IS NOT THE READ OF A DELETE AND NOT THE READ OF AN UPDATE EITHER. Four columns, exactly the ones legacy
 * selects - the identifier, the completion, the provider and its parameters - and none of the rest: a complete
 * resolves its provider from the stored PROVIDER_ID alone, without the fallback by the legacy type of the
 * activity, because the select of legacy carries no TYPE_ID for that fallback to read. The row handed over by a
 * caller is not honoured either, for the reason the read of an update states: only the delete of legacy honours
 * one.
 *
 * THE RIGHT TO READ IS NOT ASKED ABOUT (item B4, `CHECK_PERMISSIONS => 'N'`). The storage boundary checks
 * nothing by construction, which is precisely the reading legacy performs here whatever the caller asked for:
 * the state before a completion is a value of the operation and not an answer to a reader. The perm-aware
 * reading of an activity belongs to the facade.
 *
 * A ROW THAT IS NOT THERE ENDS THE OPERATION. Legacy answers a plain false before it has done anything at all -
 * no hook of the provider, no update - so the step is aborting and the two rows below never run.
 *
 * @internal
 */
final class LoadStateForComplete implements PipelineStepInterface
{
	/**
	 * There is no activity with that id. The message is the comment legacy answers its false with.
	 */
	public const ERROR_NOT_FOUND = 'CRM_ACTIVITY_NOT_FOUND';

	/**
	 * The run reached this step with no activity to complete, which the entry point of the service refuses to
	 * build. It is a mistake of whoever assembled the run rather than of the caller.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The columns of the model legacy selects: the identifier, the completion row 2 compares against, and the
	 * provider row 3 is resolved from. The fourth column of the select - the parameters of the provider - is no
	 * column of the model and arrives as the payload of the state.
	 */
	private const FIELDS_OF_THE_READ = [
		Activity::id,
		Activity::isCompleted,
		Activity::providerId,
	];

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
		return 'LoadStateForComplete';
	}

	/**
	 * Every complete starts from the state before it. Legacy has no path around this.
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

		$activity = $this->repository->getById($activityId, self::FIELDS_OF_THE_READ);
		if ($activity === null)
		{
			return $result->addError(new Error(
				"Could not find CrmActivity(ID = {$activityId})",
				self::ERROR_NOT_FOUND,
			));
		}

		$context->setPreviousState(
			$activity,
			$this->repository->getProviderPayload($activityId) ?? new ProviderPayload(),
			new StorageFieldTransit(),
		);

		return $result;
	}

	/**
	 * A complete of an activity that is not there performs none of its effects.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}
}
