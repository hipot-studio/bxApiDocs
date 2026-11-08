<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Counter\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Tells the monitor of the counters of the CRM what the update did to the activity: row 35 of the owner table
 * STEP-UPDATE (crm_activity.php:963-970), checklist item C7.9.
 *
 * WHAT THE MONITOR DOES WITH IT. Two things, and only the first of them happens here and now: where the change
 * matters to what is counted, the table the counters are computed out of is brought into line with the activity
 * straight away; the change itself also goes into a collection the monitor works through at the end of the request,
 * where the counters of the users the activity concerns are invalidated. So the observable effect of the row is the
 * rows of the countable activities of the owners; the invalidation is the monitor's own schedule.
 *
 * BOTH SIDES ARE STATES AND BOTH MOMENTS COME FROM ROW 32. The monitor decides whether anything countable moved by
 * comparing the two - the completion, the person responsible, the deadline, the marker of the incoming channel, the
 * owners and the moment the counter turns light at - so a side computed here would be a second answer. The moments
 * are the two values row 32 left behind ({@see PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT} and
 * {@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}); null is an answer of its own, because a completed activity
 * turns light at no moment at all.
 *
 * THE ROW IS UNCONDITIONAL. Legacy tells the monitor about every update - one that moved nothing countable as much
 * as one that moved everything - and every rule about what is worth counting belongs to the monitor.
 *
 * THE DEADLINE OF EACH SIDE IS THE VALUE ITS COLUMN CARRIES, THE SENTINEL INCLUDED
 * ({@see DeadlineSentinelGuard::columnOf()}): legacy hands the monitor the two rows it fetched, and the factory of
 * the change reads the sentinel as no deadline of its own accord. The identifier travels on both sides because the
 * factory reads it off the previous one.
 *
 * @internal
 */
final class NotifyCounterMonitor implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. A change
	 * is about an activity, so there is nothing to tell.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. That state is one side of the change, and
	 * the monitor told one side alone would leave the owners the activity has left counting it.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'NotifyCounterMonitor';
	}

	/**
	 * Every update is told to the monitor. See the note of the class.
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

		$previous = $context->getPreviousActivity();
		$activity = $context->getActivity();

		Monitor::getInstance()->onActivityUpdate(
			self::legacyFieldsOf($previous, $activityId),
			self::legacyFieldsOf($activity, $activityId),
			self::legacyBindingsOf($previous),
			self::legacyBindingsOf($activity),
			self::momentOf($context, PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT),
			self::momentOf($context, PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT),
		);

		return $result;
	}

	/**
	 * An activity whose counters could not be told about is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * One state of the activity in the legacy form the change is read out of: the five values it is built of.
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
	 * The set of bindings of the state in the legacy shape: which of the owners keep a counter is the monitor's
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

	/**
	 * One of the two moments row 32 left behind. A run whose row 32 failed carries neither, and no moment is what
	 * legacy would have handed over there as well.
	 */
	private static function momentOf(PipelineContext $context, string $runValue): ?DateTime
	{
		if (!$context->hasRunValue($runValue))
		{
			return null;
		}

		$moment = $context->getRunValue($runValue);

		return $moment instanceof DateTime ? $moment : null;
	}
}
