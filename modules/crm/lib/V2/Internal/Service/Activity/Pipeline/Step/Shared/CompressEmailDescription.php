<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\V2\Internal\Service\Activity\ActivityLifecycleService;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Turns the description of a letter into the preview its column keeps: step 7 of the owner table of an add
 * (crm_activity.php:152-156), step 9 of the owner table of an update (:620-638), checklist item C5.
 *
 * THE FULL TEXT OF A LETTER IS NOT A COLUMN OF AN ACTIVITY. It is stored as a row of its own and bound to the
 * activity, and what stays in the column is a preview of it - so this step has two outcomes, not one: the
 * preview in the model and the full text handed to the step that binds the body
 * ({@see PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION}). The carrier is needed because the binding
 * happens below the write - the row of the body names an activity that has no identifier yet at this point -
 * and because the column no longer holds the text by then. Without it the body of the letter would be the
 * preview, which is the quirk of legacy the design refuses to reproduce silently.
 *
 * WHAT AN EMAIL IS, ASKED IN ONE PLACE. The rules of the branch of a letter - which column gates what, how the
 * preview is made, which provider owns the limit - live in {@see EmailBranch}; this step asks it and holds no
 * knowledge of an email of its own. Its own business is the gate of the pipeline and the carrier of the run.
 *
 * ONE CLASS, TWO OPERATIONS, AND THE MODE IS NAMED BY WHOEVER DECLARES THE PIPELINE. The gates differ - an add
 * compresses the description of anything belonging to the provider of an email (:153), an update only where the
 * caller gave a description and the activity already is a compressed letter (:621-624) - and so does what the run
 * is left with. An add always hands the full text over, because a new letter always gets a body of its own; an
 * update hands it over only where the text really differs from the stored body, and says so with a flag of the run
 * ({@see PipelineContext::RUN_VALUE_MAIL_BODY_CHANGED}), because a letter saved again with the body it already has
 * keeps that body and everything pointing at it (:626-636).
 *
 * THE COMPARISON HAPPENS BEFORE THE COMPRESSION AND THE FLAG IS READ LONG AFTER IT. The column is a preview by the
 * time the row is written, so the question "is this a different letter" has exactly one moment where it can still
 * be asked - here, on the text the caller gave, above the event and above the write. What the answer is for
 * belongs to the step rebinding the body, four rows below.
 *
 * THE MOMENT DIFFERS BETWEEN THE TWO PIPELINES, and it is an invariant rather than a mistake (LBS-1 section 13
 * items 1-2): an add compresses AFTER the event before a write and an update BEFORE it, so a subscriber of an
 * add sees the full text and a subscriber of an update the preview. The order is held by the declarations of
 * the pipelines alone; neither this step nor the carrier of the rules says anything about it.
 *
 * @internal
 */
final class CompressEmailDescription implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity to compare the stored body of, which the entry point of the
	 * service refuses to build. Only an update asks the question at all.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private const OPERATIONS = [
		ActivityLifecycleService::OPERATION_ADD,
		ActivityLifecycleService::OPERATION_UPDATE,
	];

	/**
	 * @param string $operation which of the pipelines this instance belongs to, named by whoever declares it.
	 * @throws ArgumentException on an operation this step has no gate for yet.
	 */
	public function __construct(private readonly string $operation)
	{
		if (!in_array($operation, self::OPERATIONS, true))
		{
			throw new ArgumentException(
				"The '{$operation}' operation over an activity has no compression of its own yet",
				'operation',
			);
		}
	}

	public function getName(): string
	{
		return 'CompressEmailDescription';
	}

	/**
	 * An add takes an activity belonging to the provider of an email, whatever its type of provider says: legacy
	 * asks the one column here and the pair of them only below the write, when it binds the body. An update takes
	 * a letter that already is a preview of a body stored apart, and only where the caller gave a description at
	 * all - there is nothing to compress and nothing to compare in an update that says nothing about the text.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		$activity = $context->getActivity();

		if (!$this->isUpdate())
		{
			return EmailBranch::isEmail($activity);
		}

		return $activity->getDescription() !== null && EmailBranch::isCompressedEmail($activity);
	}

	public function execute(PipelineContext $context): Result
	{
		return $this->isUpdate()
			? $this->compressTheDescriptionOfAnUpdate($context)
			: $this->compressTheDescriptionOfAnAdd($context)
		;
	}

	/**
	 * Legacy interrupts nothing over a letter it could not compress.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	private function isUpdate(): bool
	{
		return $this->operation === ActivityLifecycleService::OPERATION_UPDATE;
	}

	private function compressTheDescriptionOfAnAdd(PipelineContext $context): Result
	{
		$context->setRunValue(
			PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION,
			EmailBranch::compressDescription($context->getActivity()),
		);

		return new Result();
	}

	/**
	 * The order is the order of legacy and it is not interchangeable: the stored body is compared with the text
	 * the caller gave, and only then does the column become the preview of it.
	 *
	 * A RUN WITHOUT AN ACTIVITY IS REPORTED AND NOTHING IS COMPRESSED. There is no such update - the entry point
	 * of the service refuses to build one - and compressing on the way to a comparison against the body of
	 * activity zero would leave the letter with a preview whose full text nobody carries.
	 */
	private function compressTheDescriptionOfAnUpdate(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$activity = $context->getActivity();
		$hasBodyChanged = EmailBranch::hasMailBodyChanged($activityId, (string)$activity->getDescription());

		$uncompressedDescription = EmailBranch::compressDescription($activity);
		if ($hasBodyChanged)
		{
			$context->setRunValue(
				PipelineContext::RUN_VALUE_UNCOMPRESSED_DESCRIPTION,
				$uncompressedDescription,
			);
			$context->setRunValue(PipelineContext::RUN_VALUE_MAIL_BODY_CHANGED, true);
		}

		return $result;
	}
}
