<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Summary;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Item;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use CCrmOwnerType;

/**
 * What the fields of a deal say about it once they are read together: whether the deal has outlived
 * the date it was planned to close on, how much of what was planned around it is overdue, and how
 * long nothing has been happening to it.
 *
 * These are facts about the deal itself, so they travel inside the section of its fields and not as a
 * section of their own: a model that had to look for the state of a deal in two places would find one
 * of them and answer by half of the picture.
 *
 * Two of the three are counted over what has already been read - the fields of the deal and the row
 * of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT} - and cost no query at all. The third one does
 * cost a query: the module answers about the overdue business of an entity by counters and flags and
 * by no list, and a count is all the signal needs.
 *
 * Whether a deal has been talked to lately is read of `LAST_ACTIVITY_TIME` and not of
 * `LAST_COMMUNICATION_TIME`: the first is a column of the deal and arrives with the row that is read
 * anyway, and the second is a read of its own for a fact of the same order.
 *
 * What a deal is about - an objection, a competitor named, a contact person replaced - is left to the
 * model. Recognizing it by rules here would mean a heuristic written in one language, and a portal is
 * spoken to in whichever language it was sold in.
 *
 * @internal
 */
class DealSignalsReader
{
	/**
	 * @param Item $deal The deal as {@see DealSelector::selectDetails()} read it: the fields are asked
	 * of it and not of the record {@see DealDetailsReader} built, which renames them and tells a flag
	 * as the `Y` or the `N` of the compatible form.
	 * @param array $dealRow The row of {@see SummaryLimits::DEAL_SUMMARY_EXTRA_SELECT}.
	 * @return array{isOverdue: bool, overdueDays: int, overdueActivities: int, silenceDays: int|null}
	 * An open deal is overdue once the day it was planned to close on is behind, so the least a deal
	 * that is overdue can be late by is one whole day: the planned date is a date and not a moment, and
	 * a deal planned for today is late by nothing until today is over.
	 * The days of silence are `null` when the deal has never been touched, which is not the same fact
	 * as a touch of today.
	 */
	public function read(Item $deal, array $dealRow): array
	{
		$now = new DateTime();
		$today = new Date();

		$closeDate = $deal->get(Item::FIELD_NAME_CLOSE_DATE);
		$overdueDays =
			$deal->get(Item::FIELD_NAME_CLOSED) === false && $closeDate instanceof Date
				? self::daysBetween($closeDate, $today)
				: 0
		;

		$lastActivity = $dealRow[Item::FIELD_NAME_LAST_ACTIVITY_TIME] ?? null;

		return [
			'isOverdue' => $overdueDays > 0,
			'overdueDays' => $overdueDays,
			'overdueActivities' => $this->countActivities(
				self::overdueActivitiesFilter((int)($dealRow[Item::FIELD_NAME_ID] ?? 0), $now),
			),
			'silenceDays' => $lastActivity instanceof Date ? self::daysBetween($lastActivity, $now) : null,
		];
	}

	/**
	 * The business of the deal that was due and was never done, counted over what belongs to the deal.
	 *
	 * It is what belongs to it and not what is bound to it: by the model of the bindings a letter of
	 * another owner is tied to the deal as well, and the signal answers how much of the business of
	 * this deal is overdue. It is the same measure the communications of the deal are read by.
	 *
	 * Business with no date of its own is kept at the last day the storage can hold, so it never falls
	 * under a deadline earlier than now and needs no exception here.
	 */
	private static function overdueActivitiesFilter(int $dealId, DateTime $now): array
	{
		return [
			'=OWNER_TYPE_ID' => CCrmOwnerType::Deal,
			'=OWNER_ID' => $dealId,
			'=COMPLETED' => 'N',
			'<DEADLINE' => $now,
		];
	}

	protected function countActivities(array $filter): int
	{
		return ActivityTable::getCount($filter);
	}

	/**
	 * Whole days from the earlier date to the later one, counted by the calendar so that a change of
	 * the clock does not add or drop a day.
	 *
	 * A date ahead of the one it is counted against answers no days at all: a deal touched in the future
	 * has not been silent for as long as the clocks disagree.
	 */
	private static function daysBetween(Date $from, Date $to): int
	{
		$difference = $from->getDiff($to);

		return $difference->invert === 1 ? 0 : (int)$difference->days;
	}
}
