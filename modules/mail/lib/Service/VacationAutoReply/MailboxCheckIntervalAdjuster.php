<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Helper\Mailbox;
use Bitrix\Mail\Internals\VacationAutoReplyTable;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Type\DateTime;

/**
 * Check interval of a mailbox for as long as the vacation period lasts. A mailbox connected by the modern
 * connector is checked once a day, and an answer a day late is worth little, so the period shortens the
 * interval and the mailbox gets back exactly the value that was found once the period is over.
 *
 * The value found is kept in ORIGINAL_PERIOD_CHECK of the setting and the value put in its place in
 * SHORTENED_PERIOD_CHECK: both are bookkeeping of this class and not part of the setting the user sees.
 * The pair is what makes the giving back safe - the second number says whether PERIOD_CHECK still holds
 * the writing of this class, and it says so even after the option below has been turned. Of the mailbox
 * itself only PERIOD_CHECK is written - the sync lock and the options next to it belong to the sync,
 * which rewrites them under that lock. The new interval is brought into effect by starting that sync,
 * not by writing the schedule it keeps there.
 */
class MailboxCheckIntervalAdjuster
{
	/** Minutes between checks of a mailbox whose period is on. */
	public const SHORTENED_INTERVAL_OPTION = 'vacation_auto_reply_sync_interval';

	/** Ten minutes, the interval the module gives a public CRM mailbox. */
	private const DEFAULT_SHORTENED_INTERVAL = 10;

	/** Mailbox::sync() floors the interval at a minute, so a smaller number would only be a lie. */
	private const MIN_INTERVAL = 1;

	/**
	 * How long before the start of the period the interval is worth shortening. A vacation saved a month
	 * in advance has no letters to answer yet, and a mailbox kept on frequent checks all that month is a
	 * month of knocking on a mail server for nothing. A day is generous next to the hour between the
	 * passes of the agent that shortens it.
	 */
	private const SHORTEN_LEAD_SECONDS = 86400;

	/**
	 * Mailboxes one pass of the agent takes on, in either direction. A pass is two writes per mailbox and
	 * one query of a table that holds a row per mailbox on vacation, so the pack is what a mailbox waits
	 * for, not what the pass costs: a holiday that ends for a whole portal at once must not keep the last
	 * of them on frequent checks for days.
	 */
	public const AGENT_PACK_SIZE = 500;

	/**
	 * Background syncs one instance of this class hands out. The agent builds one instance per pass and a
	 * save builds its own for the single mailbox it saves, so what this bounds is the tail of a pass - and
	 * the everyday pass, with one or two periods starting, never comes near the number.
	 *
	 * What it is here for is a portal that goes on holiday at once. Every job is a whole IMAP session, the
	 * jobs run one after another at the end of the hit, and a full pack of them would hold the worker
	 * through five hundred sessions. A mailbox left without one loses the head start alone, and a head
	 * start is not a promise: it is shortened all the same, and the shortening comes a day before the
	 * period starts, so the check it was due anyway mostly falls into the first hours of the vacation.
	 */
	public const SYNC_JOBS_PER_PASS = 10;

	private int $syncJobsGiven = 0;

	/**
	 * Shortens the check interval of the mailbox, remembers the interval it had and wakes the sync up so
	 * that the shortened interval starts working from this moment on and not from the next check.
	 *
	 * What decides is the interval the mailbox has now and not whether something is already remembered:
	 * PERIOD_CHECK has owners of its own - the mailbox settings, the public CRM mailbox - and a mailbox
	 * they moved back to a daily check in the middle of the period has to be shortened again. The value
	 * remembered first time round stays as it is: it is the one the mailbox is to get back, and writing
	 * the shortened interval over it would leave the mailbox on frequent checks for good. The value put
	 * in its place is written down anew every time, because that is the number the giving back looks for.
	 *
	 * A pass that has just selected the mailbox hands its interval in instead of having it read again, and
	 * the value is then as old as the pass. All it decides is whether there is anything to shorten and, the
	 * first time round, what to remember, so it can be wrong in one direction only: a mailbox given a check
	 * more frequent than the shortened one while the pass was running is carried past the early return by
	 * the older number, has the shortened interval written over the more frequent one and has that older
	 * number remembered as the one it is owed back. It takes a write of another owner inside the pass and
	 * below the shortened interval; at equal numbers it is the ambiguity restore() names already.
	 *
	 * @param int|null $rememberedInterval ORIGINAL_PERIOD_CHECK of the setting.
	 * @param int|null $currentInterval PERIOD_CHECK of the mailbox; read here when the caller has none.
	 */
	public function shorten(int $mailboxId, ?int $rememberedInterval, ?int $currentInterval = null): void
	{
		$currentInterval ??= $this->readMailboxInterval($mailboxId);
		$shortenedInterval = $this->resolveShortenedInterval();

		if ($currentInterval === null || $currentInterval <= $shortenedInterval)
		{
			// The mailbox is checked at least as often already: there is nothing to shorten and, above
			// all, nothing to remember - a remembered shortened interval never goes away again
			return;
		}

		// Written down before the mailbox is touched, and only a write that went through lets the mailbox
		// be touched at all: bookkeeping ahead of a shortening that never happened costs one write of the
		// same number back, while a mailbox shortened behind the back of the bookkeeping cannot be
		// repaired - no pass would ever pick it up again
		if (!$this->remember($mailboxId, $rememberedInterval ?? $currentInterval, $shortenedInterval))
		{
			return;
		}

		if ($this->writeMailboxInterval($mailboxId, $shortenedInterval))
		{
			$this->scheduleSyncWithinLimit($mailboxId);
		}
	}

	/**
	 * Gives the mailbox back the interval it had before the period shortened it. Nothing remembered means
	 * nothing was shortened, so a second pass over the same mailbox does nothing.
	 *
	 * The remembered value goes back only over the very number this class wrote down as it shortened, and
	 * not over whatever the option says by now: the option is a knob of operation, and a mailbox whose
	 * interval stopped matching the turned knob would be left on frequent checks with nobody to free it.
	 * Any other number means PERIOD_CHECK has changed hands since - the mailbox settings, the legacy
	 * update path - and the number they wrote is the current one, so all that is left to do is to forget
	 * what the period remembered. The one owner this cannot tell apart is one who wrote the very same
	 * number: a mailbox made public to CRM mid-period is given ten minutes, and while the option stands at
	 * its default of ten the end of the period puts the interval of the mailbox back over them.
	 *
	 * @param int|null $rememberedInterval ORIGINAL_PERIOD_CHECK of the setting.
	 */
	public function restore(int $mailboxId, ?int $rememberedInterval): void
	{
		if ($rememberedInterval === null)
		{
			return;
		}

		$this->restoreRemembered($mailboxId, $rememberedInterval, $this->readWrittenInterval($mailboxId));
	}

	/**
	 * Keeps the remembered interval in step with an owner of PERIOD_CHECK other than the period. The field
	 * belongs to more than this feature - a mailbox made public to CRM is given ten minutes, an admin form
	 * writes whatever it is told - while a period that shortened the mailbox holds the value it found there
	 * to put it back at the end. A write of another owner replaces that value, and the remembered one stops
	 * being the interval of the mailbox: given back later, it would take the mailbox off the schedule its
	 * owner has just chosen.
	 *
	 * A write of the very number the period itself put there is the case no comparison of values can tell
	 * apart, and the reason this method exists at all: the mailbox stays shortened, and only the interval to
	 * give back changes. Any other write means PERIOD_CHECK no longer holds the writing of this class - the
	 * bookkeeping goes, and a pass of the agent shortens the mailbox anew from the value its owner left.
	 */
	public function rebaseRememberedInterval(int $mailboxId, int $ownInterval): void
	{
		$row = VacationAutoReplyTable::getRow([
			'select' => ['ORIGINAL_PERIOD_CHECK', 'SHORTENED_PERIOD_CHECK'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]);

		if ($row === null || $row['ORIGINAL_PERIOD_CHECK'] === null)
		{
			return;
		}

		// A row of a portal that took the feature before the written value was kept, as in restore()
		$writtenInterval = $row['SHORTENED_PERIOD_CHECK'] === null
			? $this->resolveShortenedInterval()
			: (int)$row['SHORTENED_PERIOD_CHECK']
		;

		if ($ownInterval === $writtenInterval)
		{
			VacationAutoReplyTable::update($mailboxId, ['ORIGINAL_PERIOD_CHECK' => $ownInterval]);

			return;
		}

		$this->forget($mailboxId);
	}

	private function restoreRemembered(int $mailboxId, int $rememberedInterval, ?int $writtenInterval): void
	{
		// A row of a portal that took the feature before the written value was kept: the option is the
		// only guess left for it, and it is the guess the whole feature went by until now
		$writtenInterval ??= $this->resolveShortenedInterval();

		// Read here, and not carried in from the pass that selected the row the way the shortening carries
		// it: this comparison is the whole of deciding whether PERIOD_CHECK still holds the writing of this
		// class, and a number as old as the pass could call the writing of another owner our own and put
		// the remembered interval over it
		if ($this->readMailboxInterval($mailboxId) !== $writtenInterval)
		{
			$this->forget($mailboxId);

			return;
		}

		// The mailbox first and the bookkeeping after: a mailbox left on the shortened interval with
		// nothing remembered is the one state no later pass can repair
		if ($this->writeMailboxInterval($mailboxId, $rememberedInterval))
		{
			$this->forget($mailboxId);
		}
	}

	/**
	 * Gives their own interval back to the mailboxes whose period can no longer answer, a pack of them
	 * per call.
	 *
	 * A switched off setting is taken as well as a period that has ended: the save that switched it off
	 * may have failed between the two writes, and the invariant to keep is "an interval is remembered
	 * only while the period can still answer".
	 *
	 * @return int Mailboxes the pass took on; a full pack means there are more waiting.
	 */
	public function restoreExpired(): int
	{
		return $this->restoreRows(
			$this->restoreQuery()
				->where(
					Query::filter()
						->logic('or')
						->where('ENDS_AT', '<', new DateTime())
						->where('ACTIVE', VacationAutoReplyTable::ACTIVE_NO)
				)
				->fetchAll()
		);
	}

	/**
	 * Gives their own interval back to every mailbox that has one remembered, whatever its period says:
	 * what a feature switched off leaves behind. No letter is answered any more, saving the setting is
	 * closed together with the feature, and a mailbox nobody can free would knock on its mail server
	 * every ten minutes for good.
	 *
	 * @return int Mailboxes the pass took on; a full pack means there are more waiting.
	 */
	public function restoreAll(): int
	{
		return $this->restoreRows($this->restoreQuery()->fetchAll());
	}

	/**
	 * The order is the order of IX_MAIL_VACATION_AUTO_REPLY_RESTORE, so the pack comes out of the index already sorted
	 * instead of being sorted after it. Correctness does not depend on it: the order keeps a pack steady
	 * and carries no cursor between passes, because a pass clears ORIGINAL_PERIOD_CHECK of the rows it
	 * settles and the next pack is a different set whichever order this one came in.
	 *
	 * One row is not settled: a mailbox whose write of the interval was refused keeps what it remembered,
	 * and keeps it deliberately - see restoreRemembered, where a mailbox left on the shortened interval
	 * with nothing remembered is the one state no later pass repairs. A refusal that lasts therefore puts
	 * that row at the head of every pack and crowds the rest out. The order is not what does that: a low
	 * MAILBOX_ID did the same under the previous one.
	 */
	private function restoreQuery(): Query
	{
		return VacationAutoReplyTable::query()
			->setSelect(['MAILBOX_ID', 'ORIGINAL_PERIOD_CHECK', 'SHORTENED_PERIOD_CHECK'])
			->whereNotNull('ORIGINAL_PERIOD_CHECK')
			->setOrder(['ORIGINAL_PERIOD_CHECK' => 'ASC', 'MAILBOX_ID' => 'ASC'])
			->setLimit(self::AGENT_PACK_SIZE)
		;
	}

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	private function restoreRows(array $rows): int
	{
		foreach ($rows as $row)
		{
			$this->restoreRemembered(
				(int)$row['MAILBOX_ID'],
				(int)$row['ORIGINAL_PERIOD_CHECK'],
				$row['SHORTENED_PERIOD_CHECK'] === null ? null : (int)$row['SHORTENED_PERIOD_CHECK'],
			);
		}

		return count($rows);
	}

	/**
	 * Shortens the interval of the mailboxes whose period is about to start, a pack of them per call. The
	 * save of a period still far ahead leaves the mailbox alone, so this is what brings the shortened
	 * interval in when the vacation finally comes close.
	 *
	 * A mailbox that already has an interval remembered is taken as well. PERIOD_CHECK has owners of its
	 * own, and one of them putting the mailbox back on a daily check in the middle of the period would
	 * otherwise leave it there for the rest of the vacation: while the period can still answer, no pass
	 * gives an interval back, so this is the only place such a mailbox is shortened again. What it is owed
	 * back travels with it, so the second shortening keeps the interval remembered the first time.
	 *
	 * The mailbox is joined in, and not only asked about afterwards, so that the pass is progressive: a
	 * row it can do nothing about - a mailbox checked often enough already, a setting left behind by a
	 * mailbox that is gone - would otherwise be taken again by every pass until the period is over, and
	 * a pack full of those never reaches the mailboxes whose vacation is really starting. The interval of
	 * the mailbox comes out of that join too, instead of one query per row on top of it.
	 *
	 * @return int Mailboxes the pass took on.
	 */
	public function shortenStarting(): int
	{
		$now = new DateTime();
		$rows = VacationAutoReplyTable::query()
			->setSelect([
				'MAILBOX_ID',
				'ORIGINAL_PERIOD_CHECK',
				'MAILBOX_PERIOD_CHECK' => 'MAILBOX.PERIOD_CHECK',
			])
			->registerRuntimeField(
				(new Reference(
					'MAILBOX',
					MailboxTable::class,
					Join::on('this.MAILBOX_ID', 'ref.ID'),
				))->configureJoinType(Join::TYPE_INNER),
			)
			->where('ACTIVE', VacationAutoReplyTable::ACTIVE_YES)
			->where('MAILBOX.PERIOD_CHECK', '>', $this->resolveShortenedInterval())
			->where('STARTS_AT', '<=', self::shortenHorizon($now))
			->where('ENDS_AT', '>=', $now)
			->setLimit(self::AGENT_PACK_SIZE)
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$this->shorten(
				(int)$row['MAILBOX_ID'],
				$row['ORIGINAL_PERIOD_CHECK'] === null ? null : (int)$row['ORIGINAL_PERIOD_CHECK'],
				(int)$row['MAILBOX_PERIOD_CHECK'],
			);
		}

		return count($rows);
	}

	/**
	 * Whether the period is close enough for the frequent checks to be about its letters. A period that
	 * has started is due by the same measure.
	 */
	public function isDueToShorten(DateTime $startsAt, ?DateTime $now = null): bool
	{
		return $startsAt->getTimestamp() <= self::shortenHorizon($now ?? new DateTime())->getTimestamp();
	}

	private static function shortenHorizon(DateTime $now): DateTime
	{
		return DateTime::createFromTimestamp($now->getTimestamp() + self::SHORTEN_LEAD_SECONDS);
	}

	private function scheduleSyncWithinLimit(int $mailboxId): void
	{
		if ($this->syncJobsGiven >= self::SYNC_JOBS_PER_PASS)
		{
			return;
		}

		$this->syncJobsGiven++;
		$this->scheduleSync($mailboxId);
	}

	/**
	 * A shortened interval reaches the schedule of the mailbox only through a sync: the agent checks the
	 * mailbox when OPTIONS['next_sync'] is due, and that moment is recomputed from PERIOD_CHECK at the end
	 * of a sync pass. Without a sync of its own the first day of the vacation would still be checked on the
	 * old schedule - up to a day of letters nobody answers, which is exactly what shortening is for.
	 *
	 * The sync runs at the end of the hit and not during it: the setting the user saved must not wait for a
	 * mail server. This is how connecting a mailbox syncs the mailbox it has just written.
	 */
	protected function scheduleSync(int $mailboxId): void
	{
		Application::getInstance()->addBackgroundJob(
			static function (int $mailboxId): void {
				try
				{
					$mailbox = Mailbox::createInstance($mailboxId, false);
					if ($mailbox instanceof Mailbox)
					{
						$mailbox->sync();
					}
				}
				catch (\Throwable)
				{
					// A head start, not a promise: a mailbox that could not be synced here keeps the
					// schedule it has, and the shortened interval starts working after the check that
					// was due anyway. The sync reports its own trouble through the mailbox sync status
				}
			},
			[$mailboxId],
		);
	}

	/**
	 * The interval of the period is an option, so a provider that dislikes it can be answered without a
	 * release; a value below the minute the sync works with is no interval at all and falls back to the
	 * default, the way the public CRM mailbox of the module reads its own option.
	 */
	private function resolveShortenedInterval(): int
	{
		$configured = (int)Option::get(
			'mail',
			self::SHORTENED_INTERVAL_OPTION,
			self::DEFAULT_SHORTENED_INTERVAL,
		);

		return $configured >= self::MIN_INTERVAL ? $configured : self::DEFAULT_SHORTENED_INTERVAL;
	}

	private function readMailboxInterval(int $mailboxId): ?int
	{
		$mailbox = MailboxTable::getRow([
			'select' => ['PERIOD_CHECK'],
			'filter' => ['=ID' => $mailboxId],
		]);

		return $mailbox === null ? null : (int)$mailbox['PERIOD_CHECK'];
	}

	private function writeMailboxInterval(int $mailboxId, int $interval): bool
	{
		return MailboxTable::update($mailboxId, ['PERIOD_CHECK' => $interval])->isSuccess();
	}

	/** The pass of the agent reads this along with the rest of the row; a save has only the mailbox. */
	private function readWrittenInterval(int $mailboxId): ?int
	{
		$row = VacationAutoReplyTable::getRow([
			'select' => ['SHORTENED_PERIOD_CHECK'],
			'filter' => ['=MAILBOX_ID' => $mailboxId],
		]);

		return isset($row['SHORTENED_PERIOD_CHECK']) ? (int)$row['SHORTENED_PERIOD_CHECK'] : null;
	}

	/** @return bool Whether the bookkeeping was written: the mailbox is touched only after it was. */
	protected function remember(int $mailboxId, int $originalInterval, int $writtenInterval): bool
	{
		return VacationAutoReplyTable::update($mailboxId, [
			'ORIGINAL_PERIOD_CHECK' => $originalInterval,
			'SHORTENED_PERIOD_CHECK' => $writtenInterval,
		])->isSuccess();
	}

	private function forget(int $mailboxId): void
	{
		VacationAutoReplyTable::update($mailboxId, [
			'ORIGINAL_PERIOD_CHECK' => null,
			'SHORTENED_PERIOD_CHECK' => null,
		]);
	}
}
