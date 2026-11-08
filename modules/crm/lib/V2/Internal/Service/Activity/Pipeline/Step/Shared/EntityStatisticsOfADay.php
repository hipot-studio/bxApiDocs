<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Activity\Pipeline\Step\Shared;

use Bitrix\Crm\Statistics\DealActivityStatisticEntry;
use Bitrix\Crm\Statistics\LeadActivityStatisticEntry;
use Bitrix\Crm\V2\Internal\Entity\Activity\ActivityBinding;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Type\DateTime;

/**
 * Counts the day of every deal and lead a set of bindings names anew: the effect of row 18 of the owner table
 * STEP-ADD (crm_activity.php:283-301) and of rows 26 and 27 of STEP-UPDATE (:822-839, :844-861) - three fragments
 * of legacy carrying one and the same loop.
 *
 * WHAT IT REALLY DOES, WHICH IS NOT WHAT THE NAMES OF ITS CALLERS SUGGEST. The entry it registers is not a record
 * of any activity: it is the count of the completed calls, meetings and letters of one deal or lead on one day,
 * read out of the table of the activities from scratch. A provider activity - which is what every activity of V2
 * is - contributes nothing to those counts, so the only observable effect for one is that a row of a day which has
 * become empty is deleted (finding of the characterization of `[P1.T4]`).
 *
 * ONLY A DEAL AND A LEAD HAVE SUCH A DAY, and this is the one place saying so: a binding to anything else is
 * skipped, exactly as legacy skips it, and an owner no object exists for is refused by the aggregator itself. The
 * other half of the per-binding rule of legacy - a positive owner - is upheld by the model of a binding, which
 * cannot be built with one that is not.
 *
 * THE DEADLINE DATES THE DAY, so it is taken to midnight - and the shift of the time zone of the user is applied
 * once, by the very conversion legacy applies, over a value in the shape the column of the row is written in.
 *
 * WHOEVER CALLS THIS HAS ALREADY ANSWERED "IS THERE A REAL DEADLINE". The sentinel of "no deadline" is what the
 * per-binding rule of legacy refuses (`!IsMaxDatabaseDate`), and a caller reaches this point with a value that is
 * not it: an add reads the deadline of its run through {@see DeadlineSentinelGuard::readFrom()}, an update reads
 * the model of a state it has read, where the boundary of the storage has resolved the sentinel already.
 *
 * @internal
 */
final class EntityStatisticsOfADay
{
	/**
	 * @param ActivityBinding[] $bindings the set the day of each owner of which is to be counted anew
	 * @param DateTime $deadline a real deadline - see the note of the class on who answers that
	 */
	public static function reAggregate(array $bindings, DateTime $deadline): void
	{
		$options = ['DATE' => self::dayOf($deadline)];

		foreach ($bindings as $binding)
		{
			$ownerTypeId = $binding->getOwnerType()->getId();
			if ($ownerTypeId === OwnerType::DEAL)
			{
				DealActivityStatisticEntry::register($binding->getOwnerId(), null, $options);
			}
			elseif ($ownerTypeId === OwnerType::LEAD)
			{
				LeadActivityStatisticEntry::register($binding->getOwnerId(), null, $options);
			}
		}
	}

	private static function dayOf(DateTime $deadline): DateTime
	{
		$day = DateTime::createFromUserTime((string)$deadline);
		$day->setTime(0, 0, 0);

		return $day;
	}
}
