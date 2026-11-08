<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Counter\Monitor;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Tells the monitor of the counters of the CRM that an activity has appeared: step 26 of the owner table of an add
 * (crm_activity.php:348), checklist item C7.9.
 *
 * WHAT THE MONITOR DOES WITH IT. Two things, and only the first of them happens here and now: the table the
 * counters are computed out of is brought in line with the activity straight away, and the change itself is put
 * into a collection the monitor processes in the background at the end of the request, where the counters of the
 * users the activity concerns are invalidated. So the observable effect of the step is the rows of the countable
 * activities of the bound objects; the invalidation is the monitor's own schedule and not this step's.
 *
 * THE MOMENT THE COUNTER TURNS LIGHT AT IS READ, NEVER RECOMPUTED. It is the third argument of the call, and its
 * producer is the step filling the light counter six rows above, which left it in
 * {@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}; legacy hands it over the same way, out of the field array
 * where its own filler left it. Null is an answer and not an absence: a completed activity turns light at no
 * moment at all, and the monitor is told exactly that.
 *
 * THE STEP IS UNCONDITIONAL. Legacy tells the monitor about every add - a completed activity as much as an
 * uncompleted one - and every rule about what is worth counting belongs to the monitor.
 *
 * WHAT THE ARRAY CARRIES. The five values the change of an activity is built out of: the identifier, the deadline,
 * the completion, the person responsible and the marker of an incoming channel. The last one is carried only where
 * the caller named it, because the monitor tells "not named" from "no" and compares the two sides of the change.
 *
 * @internal
 */
final class NotifyCounterMonitor implements PipelineStepInterface
{
	/**
	 * The change is about an activity, so there is nothing to tell before the row exists. Legacy cannot reach
	 * this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_COUNTER_MONITOR_WITHOUT_ACTIVITY';

	public function getName(): string
	{
		return 'NotifyCounterMonitor';
	}

	/**
	 * Every add is told to the monitor. See the note of the class.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return true;
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		Monitor::getInstance()->onActivityAdd(
			self::legacyFieldsOf($context, $activityId),
			self::legacyBindingsOf($context->getActivity()),
			self::lightMomentOf($context),
		);

		return new Result();
	}

	/**
	 * An activity whose counters could not be told about is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The activity in the legacy form the change is read out of. The deadline travels as the text of the site and
	 * the sentinel of "no deadline" is left out: the monitor reads an absent deadline and the sentinel alike, so
	 * the guarded read says the same thing with one shape less.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context, int $activityId): array
	{
		$activity = $context->getActivity();
		$fields = [
			'ID' => $activityId,
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'RESPONSIBLE_ID' => (int)$activity->getResponsibleId(),
		];

		if ($activity->isIncomingChannel() !== null)
		{
			$fields['IS_INCOMING_CHANNEL'] = $activity->isIncomingChannel() === true ? 'Y' : 'N';
		}

		$deadline = DeadlineSentinelGuard::readFrom($context->getTransit());
		if ($deadline !== null)
		{
			$fields[DeadlineSentinelGuard::COLUMN] = (string)$deadline;
		}

		return $fields;
	}

	/**
	 * The moment the counter of the activity turns light at, as the step above resolved it. A run whose resolving
	 * step failed carries no answer at all, and "no moment" is what legacy would have handed over there.
	 */
	private static function lightMomentOf(PipelineContext $context): ?DateTime
	{
		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT))
		{
			return null;
		}

		$moment = $context->getRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT);

		return $moment instanceof DateTime ? $moment : null;
	}

	/**
	 * The effective set of bindings in the legacy shape: which of the owners the counters are about is the
	 * monitor's own rule.
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
