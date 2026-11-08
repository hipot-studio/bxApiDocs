<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Add;

use Bitrix\Crm\Activity\UncompletedActivity;
use Bitrix\Crm\Activity\UncompletedActivityChange;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\CompletionForStatistics;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Brings the read model of the uncompleted activities of every bound object up to date: step 21 of the owner
 * table of an add (crm_activity.php:308-321), checklist item C7.4.
 *
 * WHAT THE AGGREGATE IS FOR. Every CRM object keeps, per user, the nearest activity still to be done and the
 * moment its counter turns light at; the grids and the counters read that aggregate instead of the activities
 * themselves. It is written per binding and per affected user, and "per user" means two rows for one binding on
 * an add: the one of the person responsible and the common one under user zero (finding of the characterization
 * of `[P1.T7]`).
 *
 * ONLY AN ACTIVITY THAT IS NOT COMPLETE HAS ANYTHING TO AGGREGATE, and "complete" here is the refined answer of
 * the statistics and not the flag: legacy computes the predicate once, uses it for the statistics of a deal and
 * a lead and then negates the same value here, so an activity flagged complete while its status still says
 * waiting - or one completed by automation - does reach this step. There is one definition of that answer and it
 * lives beside the steps of both pipelines asking it ({@see CompletionForStatistics}).
 *
 * THE CHANGE IS DESCRIBED AS "NOTHING BEFORE, THIS AFTER". An add has no previous state at all, so the fields,
 * the bindings and the light moment of the "before" side are empty - which is exactly how the aggregate
 * recognizes an activity that has just appeared. The factory of the change is left to derive the typed values
 * out of the legacy shapes on its own: absent, 'Y' and 'N' mean three different things to it, and an absent
 * marker of an incoming channel is not the same as a negative one.
 *
 * THE LIGHT MOMENT IS THE ANSWER OF THE STEP ABOVE ({@see PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT}) and is
 * never re-derived here: legacy hands the very value its filler returned, and a second derivation would be a
 * second answer about one row.
 *
 * THE DEADLINE IS READ PROTECTEDLY and travels as the text of the site, the shape the factory parses; the
 * sentinel of "no deadline" is left out, which is what the factory itself does with it.
 *
 * @internal
 */
final class SyncUncompletedAggregate implements PipelineStepInterface
{
	/**
	 * The aggregate is keyed by the activity, so there is nothing to aggregate before the row exists. Legacy
	 * cannot reach this state: it interrupts an add on a failed insert.
	 */
	public const ERROR_NOT_STORED = 'CRM_ACTIVITY_UNCOMPLETED_AGGREGATE_WITHOUT_ACTIVITY';

	public function getName(): string
	{
		return 'SyncUncompletedAggregate';
	}

	/**
	 * An activity that is not complete for the statistics. See the note of the class on why it is that answer
	 * and not the flag of the completion.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return !CompletionForStatistics::isComplete($context->getActivity());
	}

	public function execute(PipelineContext $context): Result
	{
		$activityId = $context->getActivityId();
		if ($activityId <= 0)
		{
			return (new Result())->addError(new Error('The activity has not been stored.', self::ERROR_NOT_STORED));
		}

		UncompletedActivity::synchronizeForUncompletedActivityChange(
			UncompletedActivityChange::create(
				$activityId,
				[],
				[],
				self::legacyFieldsOf($context),
				self::legacyBindingsOf($context->getActivity()),
				null,
				self::lightMomentOf($context),
			),
		);

		return new Result();
	}

	/**
	 * An activity whose aggregate could not be written is stored all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The four values of the activity the change is built out of, in the legacy shapes its factory reads. A
	 * marker of an incoming channel nobody named is left out rather than sent as a negative one: the factory
	 * tells the two apart, and so does the aggregate.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(PipelineContext $context): array
	{
		$activity = $context->getActivity();
		$fields = [
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
	 * The effective set of bindings the write of the bindings left in the model, in the legacy shape.
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
	 * The moment the counter of the activity turns light at, as the step above derived it. A run whose step
	 * failed carries nothing, and nothing is what legacy would have had in that field as well.
	 */
	private static function lightMomentOf(PipelineContext $context): ?DateTime
	{
		if (!$context->hasRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT))
		{
			return null;
		}

		return $context->getRunValue(PipelineContext::RUN_VALUE_LIGHT_COUNTER_AT);
	}
}
