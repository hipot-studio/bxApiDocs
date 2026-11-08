<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Recycling\ActivityController as RecycleBin;
use Bitrix\Crm\Timeline\ActivityController;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Tells the timeline of the bound objects that the activity is gone: row 16 of the owner table STEP-DELETE
 * (crm_activity.php:1490-1498).
 *
 * WHAT THE TIMELINE DOES WITH IT DEPENDS ENTIRELY ON WHERE THE ACTIVITY WENT. The interfaces watching the scheduled
 * stream of every bound object are told to drop the activity either way; what happens to the records of the timeline
 * afterwards is the fork: an activity that was erased has its records erased with it, while an activity that went to
 * the recycle bin has them suspended against the row of the bin, so that a restoration finds them again
 * ({@see ActivityController::onDelete()}). So this row is the one reader of the two facts row 5 leaves behind, and
 * neither of them can be derived here.
 *
 * "IT WENT TO THE BIN" IS HALF AN ANSWER OF THE CALLER AND HALF AN ANSWER OF ROW 5. Legacy keeps one local for both:
 * it starts as the demand of the caller - which is how a delete of an activity being erased FROM the bin arrives - and
 * is raised where the transfer of row 5 succeeded. The run carries the second half only
 * ({@see PipelineContext::RUN_VALUE_MOVED_TO_RECYCLE_BIN}, a flag by the rule of a flag: raised, never lowered), and
 * the two are joined by the run itself ({@see PipelineContext::hasGoneToTheRecycleBin()}), which rows 8 and 19 ask the
 * same way.
 *
 * THE ROW OF THE BIN IS READ OFF THE TRANSFER EVEN WHERE THE TRANSFER FAILED, and reproducing that is the point of
 * the second carrier. Legacy takes the value out of the result of the transfer whenever a transfer was ATTEMPTED
 * (`isset($recycleBinResult)`) - a failed one included, where the value is whatever the failed result carries, that
 * is nothing - and asks the bin itself only where row 5 never ran at all. The two are different answers for an
 * activity that is in the bin from an earlier operation: the ask finds its row, the failed transfer says nothing. So
 * the carrier is present whenever row 5 ran, and its value may be null
 * ({@see PipelineContext::RUN_VALUE_RECYCLING_ENTITY_ID}).
 *
 * THE SET OF BINDINGS IS THE ONE ROW 6 ANSWERED, read out of the model of the run - the rows are gone, erased by row
 * 8. The row itself is unconditional, unlike rows 7 and 17: the records of the timeline of an activity bound to
 * nothing still have to go.
 *
 * THE FIELDS OF THE ACTIVITY ARE NOT HANDED OVER, AND NOTHING READS THEM. Legacy passes its field array under
 * `FIELDS` and {@see ActivityController::onDelete()} never touches the key; the pipeline of a delete already narrows
 * such a call once, in row 10, for the same reason. What the timeline really decides by are the three values above.
 *
 * @internal
 */
final class NotifyTimelineOnDelete implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The timeline
	 * is told about an activity - and refuses a non-positive one outright - so there is nothing to tell.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	private const PARAM_BINDINGS = 'BINDINGS';

	private const PARAM_MOVED_TO_RECYCLE_BIN = 'MOVED_TO_RECYCLE_BIN';

	private const PARAM_RECYCLE_BIN_ENTITY_ID = 'RECYCLE_BIN_ENTITY_ID';

	private readonly ActivityController $timeline;

	/**
	 * @param ActivityController|null $timeline the timeline of the activities; the one seam of the step.
	 */
	public function __construct(?ActivityController $timeline = null)
	{
		$this->timeline = $timeline ?? ActivityController::getInstance();
	}

	public function getName(): string
	{
		return 'NotifyTimelineOnDelete';
	}

	/**
	 * Every delete tells the timeline. What it writes, suspends or erases is its own decision.
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

		$this->timeline->onDelete($activityId, [
			self::PARAM_BINDINGS => self::legacyBindingsOf($context->getActivity()),
			self::PARAM_MOVED_TO_RECYCLE_BIN => $context->hasGoneToTheRecycleBin(),
			self::PARAM_RECYCLE_BIN_ENTITY_ID => self::recyclingEntityIdOf($context, $activityId),
		]);

		return $result;
	}

	/**
	 * An activity whose timeline could not be told is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The row of the bin the activity is kept as: the answer of the transfer where row 5 ran, and the answer of the
	 * bin itself where it did not. See the note of the class on why a failed transfer still answers.
	 */
	private static function recyclingEntityIdOf(PipelineContext $context, int $activityId): ?int
	{
		if ($context->hasRunValue(PipelineContext::RUN_VALUE_RECYCLING_ENTITY_ID))
		{
			return $context->getRunValue(PipelineContext::RUN_VALUE_RECYCLING_ENTITY_ID);
		}

		return RecycleBin::getInstance()->getRecyclingEntityId($activityId);
	}

	/**
	 * The set of bindings of the activity in the legacy shape, the one the timeline builds the identifiers of its
	 * objects out of.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(Activity $activity): array
	{
		return array_map(
			static fn(ActivityBinding $binding): array => [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			],
			$activity->getBindings() ?? [],
		);
	}
}
