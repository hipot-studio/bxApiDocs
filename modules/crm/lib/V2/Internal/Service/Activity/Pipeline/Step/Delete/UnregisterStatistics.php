<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Delete;

use Bitrix\Crm\Activity\CommunicationStatistics;
use Bitrix\Crm\V2\Internal\Entity\Activity\Activity;
use Bitrix\Crm\V2\Internal\Entity\Activity\StorageFieldTransit;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineContext;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\PipelineStepInterface;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\DeadlineSentinelGuard;
use Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared\EntityStatisticsOfADay;
use Bitrix\Main\Result;
use CCrmActivityType;

/**
 * Counts anew what the deleted activity used to be counted in: row 12 of the owner table STEP-DELETE
 * (crm_activity.php:1425-1455).
 *
 * TWO AGGREGATORS, ONE ROW, AND LEGACY HAS THEM UNDER ONE CONDITION TOO. The day of every deal and lead the
 * activity was due on is re-counted out of the rows that are left, and the statistics of the communications of the
 * contacts and companies it was bound to are told the activity is gone. Both are recomputations over the same set of
 * bindings, both are switched off by the same demand of the caller, and neither of them is the record of the
 * activity itself - that one is row 15 and stands outside this condition (item E7).
 *
 * THE DAY IS COUNTED ONLY FOR AN ACTIVITY THAT WAS COMPLETE AND HAD A DEADLINE, which is the gate legacy writes over
 * the row it fetched (:1428-1431). The completion is the flag and not the third notion of it - a delete has no
 * previous state to derive that one from and legacy reads the plain column here - and the deadline is the one of the
 * state: the model answers null both for a NULL column and for the sentinel of "no deadline", which collapses the two
 * questions of legacy without changing an answer, because under the sentinel legacy passes its outer gate and then
 * refuses every binding by `!IsMaxDatabaseDate`.
 *
 * THE STATISTICS OF THE COMMUNICATIONS ARE ASKED UNCONDITIONALLY INSIDE THE ROW. Legacy has them outside the
 * completion and the deadline and inside the demand of the caller, so an activity that was never completed still has
 * the counts of its contacts and companies recomputed. Every gate of the aggregator itself - a binding to a contact
 * or a company, a deadline, a provider taking part in these statistics at all - is its own, exactly as on an add and
 * on an update.
 *
 * THE SET OF BINDINGS IS THE ONE ROW 6 ANSWERED and it is read out of the model of the run: the rows are gone, erased
 * by row 8. The owner table gates the row on the demand of the caller alone, which the closed answer of row 6 makes
 * exact - a run that reached this far always has a set, empty or not, and legacy iterates an empty set as well.
 *
 * @internal
 */
final class UnregisterStatistics implements PipelineStepInterface
{
	private const COLUMN_TYPE_ID = 'TYPE_ID';

	public function getName(): string
	{
		return 'UnregisterStatistics';
	}

	/**
	 * An operation the caller has not switched the statistics off for. The mass operations of the portal emptying an
	 * owner recompute everything themselves once, which is what that demand is for.
	 */
	public function isApplicable(PipelineContext $context): bool
	{
		return !$context->getOperationContext()->shouldSkipStatistics();
	}

	public function execute(PipelineContext $context): Result
	{
		$activity = $context->getActivity();

		$deadline = $activity->getDeadline();
		if ($activity->isCompleted() === true && $deadline !== null)
		{
			EntityStatisticsOfADay::reAggregate($activity->getBindings() ?? [], $deadline);
		}

		CommunicationStatistics::unregisterActivity(self::legacyFieldsOf($activity, $context->getTransit()));

		return new Result();
	}

	/**
	 * An activity whose statistics could not be recomputed is deleted all the same: legacy checks nothing here.
	 */
	public function isAbortingOnFailure(): bool
	{
		return false;
	}

	/**
	 * The state of the activity in the legacy form the aggregator of the communications reads it in: what it names
	 * the owners, the day and the kind of activity by, and nothing besides that.
	 *
	 * THE DEADLINE IS THE VALUE THE COLUMN CARRIES, THE SENTINEL INCLUDED
	 * ({@see DeadlineSentinelGuard::columnOf()}). Legacy feeds this aggregator the row it fetched, and a row without
	 * a deadline carries the sentinel there: the aggregator then dates its counts by the year 9999 and, for a
	 * provider declaring statistics, really does write them. Handing over "no deadline" instead would silently drop
	 * that for every activity of an incoming channel, which is what has no deadline by construction.
	 *
	 * @return array<string, mixed>
	 */
	private static function legacyFieldsOf(Activity $activity, StorageFieldTransit $transit): array
	{
		return [
			'COMPLETED' => $activity->isCompleted() === true ? 'Y' : 'N',
			'TYPE_ID' => $transit->has(self::COLUMN_TYPE_ID)
				? (int)$transit->get(self::COLUMN_TYPE_ID)
				: CCrmActivityType::Provider,
			'PROVIDER_ID' => (string)$activity->getProviderId(),
			'PROVIDER_TYPE_ID' => (string)$activity->getProviderTypeId(),
			DeadlineSentinelGuard::COLUMN => (string)DeadlineSentinelGuard::columnOf($activity),
			'BINDINGS' => self::legacyBindingsOf($activity),
		];
	}

	/**
	 * The set of bindings of the activity in the legacy shape. Every owner travels, not the contacts and companies
	 * alone: which of them the statistics of a communication are about is the aggregator's own rule.
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
