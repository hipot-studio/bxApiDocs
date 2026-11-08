<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Counter\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Tells the monitor of the counters of the CRM that the activity is gone: row 17 of the owner table STEP-DELETE
 * (crm_activity.php:1499-1502), checklist item C7.9.
 *
 * WHAT THE MONITOR DOES WITH IT. Two things, and only the first happens here and now: the table the counters are
 * computed out of is brought into line with the removal straight away, and the removal goes into a collection the
 * monitor works through at the end of the request, where the counters of the users the activity concerned are
 * invalidated. So the observable effect of the row is the rows of the countable activities of the owners; the
 * invalidation is the monitor's own schedule.
 *
 * ONE SIDE ONLY, WHICH IS WHAT A REMOVAL IS. The change handed over is "this activity over these owners before,
 * nothing after" - the same shape row 10 hands the read model of the uncompleted activities.
 *
 * THE CONDITION IS THAT THE SET OF BINDINGS IS A SET, which row 6 answers - the same condition rows 7 and 10 carry.
 * The counters are kept per object, so an activity that was bound to nothing has none to recompute; being a set is
 * not the same as being non-empty.
 *
 * THE DEADLINE IS THE VALUE THE COLUMN CARRIES, THE SENTINEL INCLUDED ({@see DeadlineSentinelGuard::columnOf()}):
 * legacy hands the monitor the row it fetched, and the factory of the change reads the sentinel as no deadline of its
 * own accord.
 *
 * THE MOMENT THE COUNTER OF THE ACTIVITY HELD COMES OFF THE RUN, where row 10 left it
 * ({@see PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT}). It cannot be read again: row 10 is the row that erases
 * the only storage holding it, so a second reading here would answer nothing for every activity - and the monitor
 * would decide that the moment did not change and invalidate a narrower set of kinds of counter than legacy does
 * (:1502, where the local of legacy is handed over). Whether the value is carried at all is the question asked, not its
 * value: a run whose row 10 was skipped hands no moment over, exactly as legacy hands its uninitialized local.
 *
 * @internal
 */
final class NotifyCounterMonitor implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A change is
	 * about an activity, so there is nothing to tell.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	public function getName(): string
	{
		return 'NotifyCounterMonitor';
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

		$activity = $context->getActivity();

		Monitor::getInstance()->onActivityDelete(
			self::legacyFieldsOf($activity, $activityId),
			self::legacyBindingsOf($activity),
			self::momentTheCounterHeldOf($context),
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
	 * The moment the counter of the activity turned light at, as row 10 read it off the row it then dropped. Null both
	 * for an activity that had no such row and for a run where row 10 never happened - the monitor makes the same of
	 * the two, and so does the local of legacy.
	 */
	private static function momentTheCounterHeldOf(PipelineContext $context): ?DateTime
	{
		return $context->hasRunValue(PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT)
			? $context->getRunValue(PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT)
			: null
		;
	}

	/**
	 * The state of the activity in the legacy form the change is read out of: the five values it is built of. The
	 * marker of the incoming channel is there because row 4 put it there, and it is left out where nothing answered
	 * it - an absent marker is not a negative one.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity, int $activityId): array
	{
		$fields = [
			'ID' => $activityId,
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
	 * The set of bindings of the activity in the legacy shape. Which of the owners keep a counter is the monitor's
	 * own rule.
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
