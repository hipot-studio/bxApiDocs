<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\FillActLightCounter;
use Bitrix\Crm\Activity\LightCounter\ActCounterLightTimeRepo;
use Bitrix\Crm\Activity\UncompletedActivity;
use Bitrix\Crm\Activity\UncompletedActivityChange;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Drops the moment the counter of the activity would have turned light at and brings the read model of the uncompleted
 * activities of the objects it was bound to up to date: row 10 of the owner table STEP-DELETE
 * (crm_activity.php:1374-1392).
 *
 * THE MOMENT THE COUNTER HELD IS READ HERE AND NOT ABOVE, AND THAT IS AN INVARIANT (item 6 of section 13 of LBS-1):
 * the row of the activity is already erased by row 8 while the row of its counter is not - this step is what erases
 * that one - so the read finds the moment the activity really had. It is a read of one storage taken after another
 * storage has been written, which is the only reason the order 8 -> 10 cannot be rearranged; the read model below
 * compares that moment against the absence of one, and a read taken after this step had dropped the row would compare
 * nothing against nothing.
 *
 * THE THREE EFFECTS ARE ONE ROW BECAUSE THE ORDER INSIDE THEM IS THE POINT. Read the moment, drop the row of the
 * counter, then tell the read model "this before, nothing after". Split into steps of their own they would be three
 * declarations whose order nothing protects, and the middle one destroys what the first one reads.
 *
 * THE CONDITION IS THAT THE SET OF BINDINGS IS A SET, which row 6 answers - the same condition rows 7 and 17 carry.
 * The read model is kept per object and per user, so an activity that was bound to nothing has nothing to bring up to
 * date; a run whose row 6 never answered skips the row instead of telling the read model about a set nobody read.
 *
 * WHAT THE CHANGE SAYS. One side is the state of the activity as row 2 read it, together with the set of row 6; the
 * other side is empty on both counts, which is what a deletion is. The factory of the change derives the typed values
 * out of the legacy shapes itself, so nothing is pre-digested for it - and the deadline handed over is the value the
 * COLUMN of the state carries ({@see DeadlineSentinelGuard::columnOf()}) rather than the answer of the model: an
 * activity with no deadline carries the sentinel there and the factory reads it as no deadline, which is what legacy
 * hands it out of the row it fetched.
 *
 * THE MOMENT IS LEFT BEHIND FOR ROW 17 AS WELL, in the value of the run legacy keeps in the local it hands the monitor
 * of the counters at :1502 ({@see PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT}). Nobody below can read it
 * again: this row drops the only storage that held it. The value is carried whenever the row ran at all, a null in it
 * included - an activity that had no such row is a different statement from a run where this row never happened.
 *
 * @internal
 */
final class SyncLightCounterAndReadModel implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. Both the
	 * counter and the read model are keyed by the activity.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/** The reader of the moments of the light counters, as legacy asks for it (:8557-8562). */
	private const SERVICE_OF_THE_MOMENTS = 'crm.activity.actcounterlighttimerepo';

	private const COLUMN_ID = 'ID';

	public function getName(): string
	{
		return 'SyncLightCounterAndReadModel';
	}

	/**
	 * See the note of the class: the set of the bindings has to be a set, which is not the same as being non-empty.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->getActivity()->getBindings() !== null;
	}

	public function execute(PipelineContext $context): Result
	{
		$result = new Result();

		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return $result->addError(new Error('The activity has no id.', self::ERROR_NO_ID));
		}

		$momentTheCounterHeld = self::storedMomentOf($activityId);
		$context->setRunValue(PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT, $momentTheCounterHeld);

		(new FillActLightCounter())->onActDelete([self::COLUMN_ID => $activityId]);

		$activity = $context->getActivity();
		UncompletedActivity::synchronizeForUncompletedActivityChange(
			UncompletedActivityChange::create(
				$activityId,
				self::legacyFieldsOf($activity),
				self::legacyBindingsOf($activity),
				[],
				[],
				$momentTheCounterHeld,
				null,
			),
		);

		return $result;
	}

	/**
	 * An activity whose counters could not be recomputed is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The moment the counter of the activity turns light at as its row stands right now - before the drop below
	 * replaces it with nothing. Null for an activity that has no such row.
	 */
	private static function storedMomentOf(int $activityId): ?DateTime
	{
		/** @var ActCounterLightTimeRepo $moments */
		$moments = ServiceLocator::getInstance()->get(self::SERVICE_OF_THE_MOMENTS);

		return $moments->queryLightTimeByActivityId($activityId);
	}

	/**
	 * The state of the activity in the legacy form the factory of the change reads it in: the four values it picks
	 * out. The marker of the incoming channel is there because row 4 put it there, and it is left out where nothing
	 * answered it - an absent marker is not a negative one.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity): array
	{
		$fields = [
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
			DeadlineSentinelGuard::COLUMN => (string)DeadlineSentinelGuard::columnOf($activity),
		];

		if ($activity->isIncomingChannel() !== null)
		{
			$fields['IS_INCOMING_CHANNEL'] = $activity->isIncomingChannel() === true ? 'Y' : 'N';
		}

		return $fields;
	}

	/**
	 * The set of bindings of the activity in the legacy shape. Which of the owners keep a read model is the rule of
	 * the read model itself.
	 *
	 * @return array<int, array<string, int>>
	 */
	private static function legacyBindingsOf(Activity $activity): array
	{
		$bindings = [];
		foreach ($activity->getBindings() ?? [] as $binding)
		{
			$bindings[] = [
				'OWNER_TYPE_ID' => $binding->getOwnerType()->getId(),
				'OWNER_ID' => $binding->getOwnerId(),
			];
		}

		return $bindings;
	}
}
