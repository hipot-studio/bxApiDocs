<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\VacationAutoReply;

use Bitrix\Mail\Internal\Repository\VacationAutoReplyRepository;
use Bitrix\Mail\Internals\VacationAutoReplyLogTable;
use Bitrix\Mail\Internals\VacationAutoReplyTable;
use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;

/**
 * Lifecycle of the vacation auto-reply setting: what the mailbox has saved, what a save changes and
 * whether the period is running right now. Access rights are not checked here - that is the job of
 * the controller.
 */
class VacationAutoReplyService
{
	public const ERROR_PERIOD_INVALID = 'PERIOD_INVALID';
	public const ERROR_PERIOD_ORDER = 'PERIOD_ORDER';
	public const ERROR_TIMEZONE_INVALID = 'TIMEZONE_INVALID';
	public const ERROR_SAVE_FAILED = 'SAVE_FAILED';

	/**
	 * Date and time, optionally with seconds and with an offset of its own - one that `createMachineTime()`
	 * then holds to the timezone of the setting.
	 */
	private const PERIOD_BOUND_PATTERN = '/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?$/';

	private VacationAutoReplyRepository $repository;
	private MailboxCheckIntervalAdjuster $checkIntervalAdjuster;

	public function __construct(
		?VacationAutoReplyRepository $repository = null,
		?MailboxCheckIntervalAdjuster $checkIntervalAdjuster = null,
	)
	{
		$this->repository = $repository ?? new VacationAutoReplyRepository();
		$this->checkIntervalAdjuster = $checkIntervalAdjuster ?? new MailboxCheckIntervalAdjuster();
	}

	/**
	 * Setting of the mailbox as the domain sees it, or null when the mailbox has never saved one.
	 * ORIGINAL_PERIOD_CHECK stays in the storage: it is bookkeeping of the mailbox check interval,
	 * not part of the setting.
	 *
	 * @return array{
	 *     mailboxId: int,
	 *     userId: int,
	 *     active: bool,
	 *     periodId: int,
	 *     startsAt: DateTime,
	 *     endsAt: DateTime,
	 *     timezoneId: string,
	 *     body: string,
	 * }|null
	 */
	public function getSetting(int $mailboxId): ?array
	{
		$row = $this->repository->findByMailboxId($mailboxId);

		return $row === null ? null : self::toSetting($row);
	}

	/**
	 * Saves the setting of the mailbox. The period bounds are taken in the given timezone and stored
	 * in machine time; the timezone identifier is kept next to them.
	 *
	 * Result data carries the stored setting under the `setting` key.
	 */
	public function save(
		int $mailboxId,
		int $userId,
		bool $active,
		string $startsAt,
		string $endsAt,
		string $timezoneId,
		string $body,
	): Result
	{
		$result = new Result();

		$timezone = self::createTimezone($timezoneId);
		if ($timezone === null)
		{
			return $result->addError(new Error('Unknown timezone.', self::ERROR_TIMEZONE_INVALID));
		}

		$periodStart = self::createMachineTime($startsAt, $timezone);
		$periodEnd = self::createMachineTime($endsAt, $timezone);
		if ($periodStart === null || $periodEnd === null)
		{
			return $result->addError(new Error('Period bound is not a date and time.', self::ERROR_PERIOD_INVALID));
		}

		if ($periodEnd->getTimestamp() <= $periodStart->getTimestamp())
		{
			return $result->addError(new Error('Period must end after it starts.', self::ERROR_PERIOD_ORDER));
		}

		$current = $this->repository->findByMailboxId($mailboxId);

		$stored = $this->store($mailboxId, [
			'USER_ID' => $userId,
			'ACTIVE' => $active ? VacationAutoReplyTable::ACTIVE_YES : VacationAutoReplyTable::ACTIVE_NO,
			'PERIOD_ID' => self::resolvePeriodId($current, $periodStart),
			'STARTS_AT' => $periodStart,
			'ENDS_AT' => $periodEnd,
			'USER_TIMEZONE_ID' => $timezone->getName(),
			'BODY' => $body,
		]);

		$setting = $stored ? $this->getSetting($mailboxId) : null;
		if ($setting === null)
		{
			return $result->addError(new Error('Setting was not saved.', self::ERROR_SAVE_FAILED));
		}

		// The save does not carry the check interval of the mailbox, so the value remembered before it is
		// still the current one
		$this->adjustCheckInterval($setting, self::readRememberedInterval($current));

		return $result->setData(['setting' => $setting]);
	}

	/**
	 * The mailbox has been handed to another owner. The setting belongs to the mailbox and stays with it,
	 * switched off and pointing at the person who has the mailbox now: the text of the answer and the
	 * signature under it are the words of the previous owner, and sending them on behalf of the new one is
	 * the one thing that must not happen. USER_ID is rewritten because a field naming a person who no
	 * longer has the mailbox is what the send path chooses the sender by.
	 *
	 * The log of the notified goes as well. It lists the correspondents the vacation of the previous owner
	 * has already answered, and the everyday way the new owner starts is to open the screen, find the
	 * period saved there, replace the text and switch it on without touching the dates - which keeps the
	 * period and its log, so the first letter of every one of those people would silently go unanswered.
	 */
	public function handOverMailbox(int $mailboxId, int $newOwnerId): void
	{
		$current = $this->repository->findByMailboxId($mailboxId);
		if ($current === null)
		{
			return;
		}

		$stored = $this->store($mailboxId, [
			'USER_ID' => $newOwnerId,
			'ACTIVE' => VacationAutoReplyTable::ACTIVE_NO,
		]);

		if (!$stored)
		{
			// Nothing else is touched: a log cleared under a setting that still answers would answer the
			// correspondents of the previous owner a second time, which is worse than doing nothing at all
			self::logHandoverFailure($mailboxId, $newOwnerId);

			return;
		}

		VacationAutoReplyLogTable::deleteByFilter(['=MAILBOX_ID' => $mailboxId]);

		// The mailbox of the new owner must not be left on the frequent checks of a period that answers
		// nobody any more, and the pass of the agent is an hour away
		$this->checkIntervalAdjuster->restore($mailboxId, self::readRememberedInterval($current));
	}

	/**
	 * @param array{active: bool, startsAt: DateTime, endsAt: DateTime} $setting
	 */
	/**
	 * Told when another owner of PERIOD_CHECK writes the interval of the mailbox.
	 *
	 * @see MailboxCheckIntervalAdjuster::rebaseRememberedInterval()
	 */
	public function rebaseRememberedInterval(int $mailboxId, int $ownInterval): void
	{
		$this->checkIntervalAdjuster->rebaseRememberedInterval($mailboxId, $ownInterval);
	}

	public function isPeriodRunning(array $setting, ?DateTime $now = null): bool
	{
		if (!$setting['active'])
		{
			return false;
		}

		$moment = ($now ?? new DateTime())->getTimestamp();

		return $moment >= $setting['startsAt']->getTimestamp()
			&& $moment <= $setting['endsAt']->getTimestamp()
		;
	}

	/**
	 * Writes the setting, once more if the mailbox has no row yet and another save of the same mailbox -
	 * a double click on the button is the everyday case - wrote one in between. The loser of that race
	 * meets the primary key of the row the winner has just added; its second try finds that row and
	 * updates it, so the user gets the setting saved instead of a five hundred.
	 *
	 * @param array<string, mixed> $fields
	 */
	private function store(int $mailboxId, array $fields): bool
	{
		try
		{
			return $this->repository->save($mailboxId, $fields);
		}
		catch (SqlQueryException)
		{
			return $this->repository->save($mailboxId, $fields);
		}
	}

	/**
	 * The mailbox is the new owner's by now and the setting still answers for the previous one, which no
	 * pass of the agent repairs: the period is running, so the record is what finishing the handover by
	 * hand starts from.
	 */
	private static function logHandoverFailure(int $mailboxId, int $newOwnerId): void
	{
		(new LoggerFactory())->createById('mail.VacationAutoReply')?->error(
			'Vacation auto-reply of a mailbox handed to another owner was not switched off.',
			[
				'mailboxId' => $mailboxId,
				'newOwnerId' => $newOwnerId,
			],
		);
	}

	/**
	 * The mailbox is checked more often while the auto-reply is on, and gets its own interval back once it
	 * is switched off or the period is over. A period about to start counts as on - the mail of the first
	 * day of the vacation has to be answered too - while one still far ahead is left to the agent, which
	 * shortens the interval when its start comes close.
	 *
	 * @param array{mailboxId: int, active: bool, startsAt: DateTime, endsAt: DateTime} $setting
	 */
	private function adjustCheckInterval(array $setting, ?int $rememberedInterval): void
	{
		if ($setting['active']
			&& !self::isPeriodOver($setting)
			&& $this->checkIntervalAdjuster->isDueToShorten($setting['startsAt'])
		)
		{
			$this->checkIntervalAdjuster->shorten($setting['mailboxId'], $rememberedInterval);

			return;
		}

		$this->checkIntervalAdjuster->restore($setting['mailboxId'], $rememberedInterval);
	}

	/**
	 * @param array{endsAt: DateTime} $setting
	 */
	private static function isPeriodOver(array $setting): bool
	{
		return (new DateTime())->getTimestamp() > $setting['endsAt']->getTimestamp();
	}

	/**
	 * @param array<string, mixed>|null $row
	 */
	private static function readRememberedInterval(?array $row): ?int
	{
		$remembered = $row['ORIGINAL_PERIOD_CHECK'] ?? null;

		return $remembered === null ? null : (int)$remembered;
	}

	/**
	 * A changed start means a new period, so the senders answered during the previous one stop
	 * counting; switching the setting off and on again inside the same period, as well as moving only
	 * the end of it, keeps the period and its log of notified senders.
	 *
	 * @param array<string, mixed>|null $current Stored row of the mailbox, as it was before this save.
	 */
	private static function resolvePeriodId(?array $current, DateTime $periodStart): int
	{
		if ($current === null)
		{
			return VacationAutoReplyTable::FIRST_PERIOD_ID;
		}

		$currentPeriodId = max((int)$current['PERIOD_ID'], VacationAutoReplyTable::FIRST_PERIOD_ID);
		$currentStart = $current['STARTS_AT'] ?? null;

		return $currentStart instanceof DateTime && $currentStart->getTimestamp() === $periodStart->getTimestamp()
			? $currentPeriodId
			: $currentPeriodId + 1
		;
	}

	/**
	 * @param array<string, mixed> $row
	 *
	 * @return array<string, mixed>
	 */
	private static function toSetting(array $row): array
	{
		return [
			'mailboxId' => (int)$row['MAILBOX_ID'],
			'userId' => (int)$row['USER_ID'],
			'active' => $row['ACTIVE'] === VacationAutoReplyTable::ACTIVE_YES,
			'periodId' => (int)$row['PERIOD_ID'],
			'startsAt' => $row['STARTS_AT'],
			'endsAt' => $row['ENDS_AT'],
			'timezoneId' => (string)$row['USER_TIMEZONE_ID'],
			'body' => (string)$row['BODY'],
		];
	}

	private static function createTimezone(string $timezoneId): ?\DateTimeZone
	{
		try
		{
			return new \DateTimeZone($timezoneId);
		}
		catch (\Exception)
		{
			return null;
		}
	}

	/**
	 * The bound comes in as local time of the user timezone; the Bitrix type carries the moment it
	 * points at, and the ORM stores that moment in machine time.
	 *
	 * A bound is allowed an offset of its own, because that is the shape the setting comes back in -
	 * the controller renders the stored period with `formatBound()` and hands it over unchanged when
	 * the auto-reply is switched off. But `new \DateTime()` lets an offset written in the value win
	 * over the timezone it is given, so one that disagrees with the timezone would silently move the
	 * period off the wall clock time of the user: it is refused instead. An offset that came from the
	 * timezone always agrees, on either side of a daylight saving change - it is rendered for the
	 * moment of the bound itself.
	 */
	private static function createMachineTime(string $value, \DateTimeZone $timezone): ?DateTime
	{
		$value = trim($value);
		if (!preg_match(self::PERIOD_BOUND_PATTERN, $value, $bound))
		{
			return null;
		}

		try
		{
			$parsed = new \DateTime($value, $timezone);
		}
		catch (\Exception)
		{
			return null;
		}

		// A day the calendar does not have is reported as a warning and silently rolled over, which
		// would move the period the user set by a couple of days
		$parseErrors = \DateTime::getLastErrors();
		if ($parseErrors !== false && ($parseErrors['error_count'] > 0 || $parseErrors['warning_count'] > 0))
		{
			return null;
		}

		if (($bound[2] ?? '') !== '' && $parsed->getOffset() !== $timezone->getOffset($parsed))
		{
			return null;
		}

		return DateTime::createFromPhp($parsed);
	}
}
