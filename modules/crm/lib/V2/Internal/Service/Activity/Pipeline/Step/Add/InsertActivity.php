<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepository;
use Bitrix\Crm\V2\Internal\Repository\Activity\ActivityRepositoryInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Throwable;

/**
 * Writes the row of the activity: step 10 of the owner table of an add (crm_activity.php:179-194), the second
 * and last step an add is interrupted on.
 *
 * THE STEP THAT DIVIDES THE PIPELINE IN TWO. Everything above it prepares the values of one row and nothing of
 * the activity exists yet; everything below it works on an activity that has an identifier, and reads that
 * identifier from the context - which is why the step hands it over there ({@see PipelineContext::getActivityId()})
 * as its first duty after the write. A step below that finds a zero there would be looking at a run whose write
 * never happened, and there is no such run: a refusal here ends the operation.
 *
 * A REFUSAL IS AN ERROR OF THE DOMAIN, NOT A THROWABLE. Legacy answers a failed insert with one registered error
 * and a false, so the caller of an add learns "it was not stored" rather than a technical reason; the storage
 * boundary of V2 answers it with an exception instead. The exception is therefore turned into the error legacy
 * registers, word for word, and the technical reason travels in the custom data of it - visible to whoever
 * reports the failure, out of the way of whoever shows it to a user. Letting the throwable out would work as
 * well, the engine aborting on it just the same, but the caller would then get the generic code of a broken step
 * instead of a named one.
 *
 * THE TEXTS OF THE CALLER ARE PUT BACK HERE, and that is the other half of the step escaping the emoji
 * ({@see \Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EncodeEmoji}). Legacy escapes them right
 * before the write and restores them right after it (:159-169 against :211-218), so the escaped shape lives for
 * the length of the write and nothing but the row ever sees it: the thirty steps below the insert - the search
 * index, the timeline, the record of the activity stream, the event of a calendar, the notification of a
 * messenger - all read the text as it was given. The restoration is bound to the write rather than being a step
 * of its own, because a step of its own would be a row the owner table does not have; and it belongs to the add
 * alone, an update deliberately keeping the escaped shape (row D14 of the registry of intentional deviations).
 *
 * @internal
 */
final class InsertActivity implements PipelineStepInterface
{
	/**
	 * The row could not be written. The message is the one legacy registers; the reason travels in the custom
	 * data under {@see self::ERROR_DETAIL_REASON}.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_NOT_STORED';

	public const ERROR_DETAIL_REASON = 'reason';

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
		return 'InsertActivity';
	}

	/**
	 * Every add writes its row. Legacy has no path around this.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();

		try
		{
			$id = $this->repository->add($activity, $context->getPayload(), $context->getTransit());
		}
		catch (Throwable $error)
		{
			return (new Result())->addError(new Error(
				'DB connection was lost.',
				self::ERROR_NOT_STORED,
				[self::ERROR_DETAIL_REASON => $error->getMessage()],
			));
		}

		$activity->internalSet(Activity::id, $id);
		self::putTheTextsOfTheCallerBack($context);

		return new Result();
	}

	/**
	 * Nothing of an activity whose row was refused exists, so nothing below may run.
	 */
	public function isAbortingOnFailure(): bool
	{
		return true;
	}

	/**
	 * Puts the subject and the description back as they stood before the emoji of them were escaped. Both
	 * values are the ones the caller gave, so they are put back through the hydration path: this is the end of
	 * an escaping that served the write, not a change of the activity anybody asked for.
	 */
	private static function putTheTextsOfTheCallerBack(PipelineContext $context): void
	{
		$activity = $context->getActivity();

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_UNENCODED_SUBJECT))
		{
			$activity->internalSet(
				Activity::subject,
				$context->getRunValue(PipelineContext::RUN_VALUE_UNENCODED_SUBJECT),
			);
		}

		if ($context->hasRunValue(PipelineContext::RUN_VALUE_UNENCODED_DESCRIPTION))
		{
			$activity->internalSet(
				Activity::description,
				$context->getRunValue(PipelineContext::RUN_VALUE_UNENCODED_DESCRIPTION),
			);
		}
	}
}
