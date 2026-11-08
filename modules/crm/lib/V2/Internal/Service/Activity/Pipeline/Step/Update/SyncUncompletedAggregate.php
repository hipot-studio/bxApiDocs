<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Update;

use Bitrix\Crm\Activity\UncompletedActivity;
use Bitrix\Crm\Activity\UncompletedActivityChange;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Brings the read model of the uncompleted activities of every object the activity is or was bound to up to date:
 * row 33 of the owner table STEP-UPDATE (crm_activity.php:884-909), checklist item C7.4.
 *
 * THE ROW IS THE ONLY READER OF THE FLAG OF THE RECOMPUTATION
 * ({@see PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH}). Five rows above raise it - the reminder
 * offsets of row 7, the write of the bindings of row 18, whichever branch of the exclusive block 21 to 23 fired,
 * the deadline of row 24 and the reminder of row 25 - each under a condition legacy raises it under. The contract
 * of a flag is that it is only ever raised: a reader asks whether the run carries it, and lowering one would
 * create a third state legacy does not have. There is nothing here that recomputes the aggregate anyway - the
 * whole point of the flag is that an update touching none of those things leaves the aggregate alone.
 *
 * IT STANDS BELOW THE RECOMPUTATION OF THE COUNTER AND THAT IS AN INVARIANT (item 5 of section 13 of LBS-1): the
 * aggregate keeps, per object and per user, the nearest activity still to be done together with the moment its
 * counter turns light at, so it needs both moments of row 32 - the one the row held before and the one it holds
 * now. Neither is derived here: a second derivation would be a second answer about one row.
 *
 * THE CHANGE IS "THIS BEFORE, THAT AFTER" AND BOTH SIDES ARE STATES. The previous fields and the previous set of
 * bindings come from the state read before the write, the current ones from the re-read state and the effective set
 * row 18 left in its model. The factory of the change derives the typed values out of the legacy shapes itself and
 * tells the shapes apart - an absent marker of an incoming channel is not a negative one, and the sentinel is not
 * a deadline - so nothing is pre-digested for it.
 *
 * TWO LOCALS OF LEGACY ARE DEAD HERE AND ARE NOT REPRODUCED. It composes a set of bindings to register and a list
 * of users to register (:887-896) and then hands the factory the two plain sets and nothing else; neither local is
 * read again anywhere. Reproducing them would be reproducing the dead half of a fragment.
 *
 * @internal
 */
final class SyncUncompletedAggregate implements PipelineStepInterface
{
	/**
	 * The run reached this step with no activity, which the entry point of the service refuses to build. The
	 * aggregate is keyed by the activity, so there is nothing to recompute.
	 */
	public const ERROR_NO_ID = 'CRM_ACTIVITY_HAS_NO_ID';

	/**
	 * The run reached this step without the state the activity was in. That state is one side of the change, and
	 * an aggregate told only the current one would leave the objects the activity has left as they were.
	 */
	public const ERROR_PREVIOUS_STATE_NOT_LOADED = 'CRM_ACTIVITY_PREVIOUS_STATE_NOT_LOADED';

	public function getName(): string
	{
		return 'SyncUncompletedAggregate';
	}

	/**
	 * An update some row above told the aggregate is out of step. See the note of the class on the flag.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return $context->hasRunValue(PipelineContext::RUN_VALUE_UNCOMPLETED_AGGREGATE_NEEDS_REFRESH);
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

		UncompletedActivity::synchronizeForUncompletedActivityChange(
			UncompletedActivityChange::create(
				$activityId,
				self::legacyFieldsOf($previous),
				self::legacyBindingsOf($previous),
				self::legacyFieldsOf($activity),
				self::legacyBindingsOf($activity),
				self::momentOf($context, PipelineContext::RUN_VALUE_PREVIOUS_LIGHT_COUNTER_AT),
				self::momentOf($context, PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT),
			),
		);

		return $result;
	}

	/**
	 * An activity whose aggregate could not be recomputed is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * One state of the activity in the legacy form the factory of the change reads it in. The marker of the
	 * incoming channel is in both states because row 20 puts it there; the deadline is the value the column of the
	 * state carries, the sentinel included, which the factory reads as no deadline of its own accord.
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
	 * The set of bindings of the state in the legacy shape. Which of the owners keep an aggregate is the rule of
	 * the aggregate itself.
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
	 * legacy would have had in those locals as well.
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
