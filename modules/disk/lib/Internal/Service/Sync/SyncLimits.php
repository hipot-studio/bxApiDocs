<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync;

use Bitrix\Disk\Driver;
use Bitrix\Disk\Infrastructure\Rest\V3\Structure\Sync\SyncPaginationStructure;
use Bitrix\Main\Config\Option;

/**
 * The numbers the synchronisation contour publishes and then holds itself to.
 *
 * Three of them are portal settings: how long the journal keeps a record, how wide the window is
 * inside which a change may still be invisible to a reader, and how long an interaction keeps an
 * object in sight. The polling bounds are derived from the first two rather than being settings of
 * their own, because three independent numbers drift apart: a portal that shortens its journal
 * would keep publishing a polling step longer than the journal lives, and a client obeying the
 * published step would come back to a position the journal had already dropped. Deriving them means
 * shortening the retention shortens the maximum step with it.
 *
 * The values themselves are not final (open question Q-3, and the safe window is still to be
 * measured); the formulas and the published field set are. Approving other numbers changes what
 * the options hold, not the code.
 */
final class SyncLimits
{
	public const OPTION_JOURNAL_RETENTION_DAYS = 'disk_sync_journal_retention_days';
	public const OPTION_SAFE_WINDOW_SECONDS = 'disk_sync_safe_window_seconds';
	public const OPTION_INTERACTION_WINDOW_DAYS = 'disk_sync_interaction_window_days';

	public const DEFAULT_JOURNAL_RETENTION_DAYS = 30;

	/**
	 * How long an object a user touched stays visible to them on that interaction alone.
	 *
	 * Preliminary, like the retention beside it: the width belongs to the numbers of the contract the
	 * owner approves (open question Q-3), and what the code fixes is that the width is a setting and is
	 * published, not the number itself. It is deliberately far longer than the retention of the journal:
	 * the window is how long a document stays in sight, while the retention is only how long the record
	 * about it survives.
	 */
	public const DEFAULT_INTERACTION_WINDOW_DAYS = 180;

	/**
	 * Deliberately generous while the real width is unmeasured: the window has to cover everything
	 * that can delay a committed change from being seen by the next reader - a long write
	 * transaction, replication lag of a read replica, and the clocks of the portal nodes drifting
	 * apart. Too wide costs a client a slightly longer re-read on every poll; too narrow loses
	 * changes silently, which is the failure this contour must not have.
	 */
	public const DEFAULT_SAFE_WINDOW_SECONDS = 300;

	/**
	 * What the comparison of two clocks is allowed to lose.
	 *
	 * `UPDATE_TIME` is written by php on the node performing the mutation, and whatever it is weighed
	 * against comes off another clock: the database stamps the record published about it, and the node
	 * answering a call computes the boundary a page is read against. The drift between those clocks is
	 * the whole of what this covers.
	 *
	 * One value rather than one per reader: two numbers answering for one quantity drift apart on their
	 * own. Deliberately not a setting either - the safe window is revisited when replication degrades,
	 * while the drift of the clocks gives an administrator no lever to pull. A minute is far above any
	 * portal keeping its nodes in step and never above the shortest step a client is allowed to poll at,
	 * which is what lets the contour ask a client to come back instead of publishing the number to it.
	 */
	public const CLOCK_TOLERANCE_SECONDS = 60;

	/**
	 * The heavy page ceiling: the light one is the published {@see SyncPaginationStructure::MAX_LIMIT},
	 * and a page of cards whose expensive sections were asked for is served at half of it. Only
	 * published here - applying it to a request belongs to the method that has sections to weigh.
	 */
	public const PAGE_SIZE_MAX_HEAVY = 50;

	private const SECONDS_IN_DAY = 86400;

	/**
	 * The shortest journal the contract allows a portal to keep. A week, because that is the floor
	 * published to clients, and everything a client is promised is derived from the retention: a
	 * shorter one would publish a maximum polling step the contract does not allow and would drop
	 * the position of a client that was switched off over a long weekend. Setting a smaller number
	 * is a misconfiguration and is read as the floor rather than honoured.
	 */
	private const MIN_RETENTION_DAYS = 7;

	/**
	 * A window of nothing would empty the interaction set of the whole portal on the next run of the
	 * aging, and the page of recent documents with it. Switching the mechanism off is done by taking
	 * the registration of the agent away, not by writing a zero into the setting.
	 */
	private const MIN_INTERACTION_WINDOW_DAYS = 1;

	/**
	 * The maximum step is a third of the retention. The remaining two thirds are the reserve a
	 * client spends on being switched off, on a run that failed and on retrying it.
	 */
	private const MAX_INTERVAL_RETENTION_DIVISOR = 3;

	/**
	 * Polling more often than the safe window only re-reads the same overlap, so the window is the
	 * floor of the published minimum. The constant below is the floor of that floor: it keeps a
	 * portal that narrows its window to nothing from publishing "poll as often as you like".
	 */
	private const MIN_INTERVAL_FLOOR_SECONDS = 60;

	/**
	 * The recommended step is a multiple of the minimum, so that the common case leaves the portal
	 * headroom over the minimum a client is merely allowed to use.
	 */
	private const RECOMMENDED_INTERVAL_FACTOR = 3;

	public function getJournalRetentionDays(): int
	{
		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_JOURNAL_RETENTION_DAYS,
			(string)self::DEFAULT_JOURNAL_RETENTION_DAYS,
		);

		return max(self::MIN_RETENTION_DAYS, $configured);
	}

	public function getJournalRetentionSeconds(): int
	{
		return $this->getJournalRetentionDays() * self::SECONDS_IN_DAY;
	}

	/**
	 * How long an object stays in the interaction set of the user who touched it.
	 *
	 * Unlike the polling bounds this one is derived from nothing: it answers for a different subject
	 * altogether - how long the portal remembers that a user reached an object - and no formula ties
	 * it to the life of the journal.
	 */
	public function getInteractionWindowDays(): int
	{
		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_INTERACTION_WINDOW_DAYS,
			(string)self::DEFAULT_INTERACTION_WINDOW_DAYS,
		);

		return max(self::MIN_INTERACTION_WINDOW_DAYS, $configured);
	}

	public function getInteractionWindowSeconds(): int
	{
		return $this->getInteractionWindowDays() * self::SECONDS_IN_DAY;
	}

	/**
	 * The effective width of the window. A window configured wider than the maximum step would
	 * leave no step satisfying both published bounds at once, so it is capped by that step: what is
	 * published is what the contour can actually honour.
	 */
	public function getSafeWindowSeconds(): int
	{
		$configured = (int)Option::get(
			Driver::INTERNAL_MODULE_ID,
			self::OPTION_SAFE_WINDOW_SECONDS,
			(string)self::DEFAULT_SAFE_WINDOW_SECONDS,
		);

		return min(max(0, $configured), $this->getPollIntervalMaxSeconds());
	}

	public function getPollIntervalMinSeconds(): int
	{
		return max($this->getSafeWindowSeconds(), self::MIN_INTERVAL_FLOOR_SECONDS);
	}

	public function getPollIntervalRecommendedSeconds(): int
	{
		$recommended = $this->getPollIntervalMinSeconds() * self::RECOMMENDED_INTERVAL_FACTOR;

		return min($recommended, $this->getPollIntervalMaxSeconds());
	}

	public function getPollIntervalMaxSeconds(): int
	{
		return intdiv($this->getJournalRetentionSeconds(), self::MAX_INTERVAL_RETENTION_DIVISOR);
	}

	public function getPageSizeMax(): int
	{
		return SyncPaginationStructure::MAX_LIMIT;
	}

	public function getPageSizeMaxHeavy(): int
	{
		return self::PAGE_SIZE_MAX_HEAVY;
	}
}
