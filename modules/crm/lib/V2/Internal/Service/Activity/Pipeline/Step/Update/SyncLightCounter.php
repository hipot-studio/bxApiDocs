<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\FillActLightCounter;
use Bitrix\Crm\Activity\LightCounter\ActCounterLightTimeRepo;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use CCrmActivityNotifyType;

/**
 * Recomputes the moment the counter of the activity turns light at, and keeps the moment it turned light at before:
 * row 32 of the owner table STEP-UPDATE (crm_activity.php:879-882).
 *
 * THE ORDER OF THE TWO READS IS AN INVARIANT AND THAT IS WHY THEY ARE ONE STEP (item 5 of section 13 of LBS-1).
 * The previous moment lives in the very row the recomputation overwrites, so it has to be read first; a step of its
 * own standing above this one would answer the same thing, but nothing would keep the two apart once somebody
 * reorders the declaration. Both moments are answers of this row and both travel as values of the run
 * ({@see PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT} and
 * {@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}), which is where legacy keeps them too - in two locals of its
 * pipeline. Their readers are the aggregate of the uncompleted activities of the row below and the monitor of the
 * counters three rows down; the timeline of row 41 reads the second one as well.
 *
 * THE STEP IS UNCONDITIONAL AND THE THREE BRANCHES LIVE INSIDE THE FILLER. Legacy asks it on every update and it
 * decides for itself: an activity that has just been completed loses its row, an uncompleted one gets the moment
 * recomputed, and an activity that was already completed is left alone and answers no moment. Lifting any of that
 * up here would be a copy of a rule the filler owns - and the last of the three is precisely why "no moment" is an
 * answer rather than an absence: the monitor of the counters reads the value of every activity, completed ones
 * included.
 *
 * THE REMINDER OFFSETS ARE THE ONES OF THE RUN AND NEVER THE ONES OF THE MODEL. Row 7 puts them there on every
 * update - the ones the operation named, or the ones the storage of the offsets holds where it named none - while
 * the model of an update carries only what the caller said, which on most updates is nothing at all. A model read
 * here would silently derive the moment off a portal-wide default.
 *
 * THE DEADLINE IS THE ONE THE COLUMN OF THE RE-READ STATE CARRIES, THE SENTINEL INCLUDED
 * ({@see DeadlineSentinelGuard::columnOf()}): legacy hands the filler the row it fetched, and a row without a
 * deadline carries that date. The derivation reads the sentinel and the absence of a deadline alike - an activity
 * with neither turns light at the year 9999, which is to say never - so the two shapes are one answer here; the
 * column is handed over because it is the shape the state really has.
 *
 * @internal
 */
final class SyncLightCounter implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The
	 * counter is keyed by the activity, so there is nothing to recompute.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. The completion of that state is what tells
	 * an activity that has just been completed - whose row is dropped - from one that was completed already.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	/** The reader of the moments of the light counters, as legacy asks for it. */
	private const SERVICE_OF_THE_MOMENTS = 'crm.activity.actcounterlighttimerepo';

	private const COLUMN_NOTIFY_TYPE = 'NOTIFY_TYPE';

	private const COLUMN_NOTIFY_VALUE = 'NOTIFY_VALUE';

	public function getName(): string
	{
		return 'SyncLightCounter';
	}

	/**
	 * Every update recomputes the counter. See the note of the class on why the completion is not asked here.
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

		$context->setRunValue(
			PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT,
			self::storedMomentOf($activityId),
		);

		$context->setRunValue(
			PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT,
			(new FillActLightCounter())->onActUpdate(
				self::legacyFieldsOf($context->getActivity(), $context->getTransit(), $activityId),
				self::legacyPreviousFieldsOf($context->getPreviousActivity()),
				self::offsetsOf($context),
			),
		);

		return $result;
	}

	/**
	 * An activity whose counter could not be recomputed is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The moment the counter of the activity turns light at as the row of it stands right now, before the
	 * recomputation replaces it. Null for an activity that has no such row.
	 */
	private static function storedMomentOf(int $activityId): ?DateTime
	{
		/** @var ActCounterLightTimeRepo $moments */
		$moments = ServiceLocator::getInstance()->get(self::SERVICE_OF_THE_MOMENTS);

		return $moments->queryLightTimeByActivityId($activityId);
	}

	/**
	 * The re-read state in the legacy form the filler reads it in: the identifier, the completion and the three
	 * values the derivation is made of. The moment travels as the text of the site, which is the shape legacy
	 * parses it back out of its fetched row.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity, StorageFieldTransit $transit, int $activityId): array
	{
		return [
			'ID' => $activityId,
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'NOTIFY_TYPE' => self::intOf($transit, self::COLUMN_NOTIFY_TYPE, CCrmActivityNotifyType::None),
			'NOTIFY_VALUE' => self::intOf($transit, self::COLUMN_NOTIFY_VALUE, 0),
			DeadlineSentinelGuard::COLUMN => (string)DeadlineSentinelGuard::columnOf($activity),
		];
	}

	/**
	 * The state before the update, of which the filler reads the completion and nothing else.
	 *
	 * @return array<string, string>
	 */
	private static function legacyPreviousFieldsOf(Activity $previous): array
	{
		return ['COMPLETED' => $previous->isCompleted() === true ? 'Y' : 'N'];
	}

	/**
	 * The reminders the run works with. See the note of the class on why the model is not asked: a run whose
	 * row 7 failed carries no answer, and no reminders is what legacy starts that variable at.
	 *
	 * @return int[]
	 */
	private static function offsetsOf(PipelineContext $context): array
	{
		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS))
		{
			return [];
		}

		return $context->getRunValue(PipelineContext::RUN_VALUE_PING_OFFSETS);
	}

	private static function intOf(StorageFieldTransit $transit, string $column, int $default): int
	{
		return $transit->has($column) ? (int)$transit->get($column) : $default;
	}
}
