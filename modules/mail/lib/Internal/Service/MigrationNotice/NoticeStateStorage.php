<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\MigrationNotice;

use Bitrix\Mail\Helper\Config\Guide;
use Bitrix\Mail\Internals\MailEntityOptionsTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Type\DateTime;

/**
 * The single point of reading and writing what a person has already been shown about the migration of
 * mail to Bitrix24 Mail.
 *
 * Every state is kept by its natural key, in a store the module already has: the announcement belongs
 * to the person and lives in the core user options next to the other hints of the module, the three
 * per-mailbox states live in the mailbox property store. That is why the feature brings no table of
 * its own, and why a deleted mailbox takes its states with it.
 *
 * The class is the single point of these states in the module: it is open only so that the table of
 * rules of {@see NoticeDecisionService} can be exercised without a database behind it.
 */
class NoticeStateStorage
{
	public const ANNOUNCEMENT_USER_OPTION_NAME = 'migration_announcement_shown';

	private const FLAG_VALUE = 'Y';
	private const DEFAULT_WEEK_HOLIDAYS = 'SA|SU';
	private const DAYS_IN_A_WEEK = 7;
	private const WEEK_DAY_NUMBERS = [
		'MO' => 1,
		'TU' => 2,
		'WE' => 3,
		'TH' => 4,
		'FR' => 5,
		'SA' => 6,
		'SU' => 7,
	];

	private const MAILBOX_PROPERTIES = [
		MailEntityOptionsTable::MIG_NOTICE_POPUP_SHOWN_PROPERTY_NAME,
		MailEntityOptionsTable::MIG_NOTICE_REMIND_AFTER_PROPERTY_NAME,
		MailEntityOptionsTable::MIG_NOTICE_BANNER_DISMISSED_PROPERTY_NAME,
	];

	public function wasAnnouncementShown(int $userId): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return \CUserOptions::GetOption(
			Guide::USER_OPTION_CATEGORY,
			self::ANNOUNCEMENT_USER_OPTION_NAME,
			null,
			$userId,
		) === self::FLAG_VALUE;
	}

	public function markAnnouncementShown(int $userId): void
	{
		if ($userId <= 0)
		{
			return;
		}

		\CUserOptions::SetOption(
			Guide::USER_OPTION_CATEGORY,
			self::ANNOUNCEMENT_USER_OPTION_NAME,
			self::FLAG_VALUE,
			false,
			$userId,
		);
	}

	/**
	 * The states of every given mailbox in one read: a caller asks about the mailboxes of a person and
	 * must not go row by row. A mailbox with nothing stored is present in the answer with an empty state.
	 *
	 * @param int[] $mailboxIds
	 * @return array<int, array{popupShown: string|null, remindUntil: int|null, bannerDismissed: bool}>
	 */
	public function getMailboxStates(array $mailboxIds): array
	{
		$mailboxIds = array_values(array_unique(array_filter(
			array_map('intval', $mailboxIds),
			static fn (int $mailboxId): bool => $mailboxId > 0,
		)));

		$states = [];
		foreach ($mailboxIds as $mailboxId)
		{
			$states[$mailboxId] = self::emptyState();
		}

		if (empty($mailboxIds))
		{
			return $states;
		}

		$rows = MailEntityOptionsTable::getList([
			'select' => ['MAILBOX_ID', 'PROPERTY_NAME', 'VALUE'],
			'filter' => [
				'=MAILBOX_ID' => $mailboxIds,
				'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
				'=ENTITY_ID' => array_map('strval', $mailboxIds),
				'=PROPERTY_NAME' => self::MAILBOX_PROPERTIES,
			],
		])->fetchAll();

		foreach ($rows as $row)
		{
			$mailboxId = (int)$row['MAILBOX_ID'];
			if (!isset($states[$mailboxId]))
			{
				continue;
			}

			$value = (string)($row['VALUE'] ?? '');

			switch ($row['PROPERTY_NAME'])
			{
				case MailEntityOptionsTable::MIG_NOTICE_POPUP_SHOWN_PROPERTY_NAME:
					$states[$mailboxId]['popupShown'] = $value === '' ? null : $value;

					break;
				case MailEntityOptionsTable::MIG_NOTICE_REMIND_AFTER_PROPERTY_NAME:
					$states[$mailboxId]['remindUntil'] = (int)$value > 0 ? (int)$value : null;

					break;
				case MailEntityOptionsTable::MIG_NOTICE_BANNER_DISMISSED_PROPERTY_NAME:
					$states[$mailboxId]['bannerDismissed'] = $value === self::FLAG_VALUE;

					break;
				default:
					break;
			}
		}

		return $states;
	}

	/**
	 * @return array{popupShown: string|null, remindUntil: int|null, bannerDismissed: bool}
	 */
	public function getMailboxState(int $mailboxId): array
	{
		return $this->getMailboxStates([$mailboxId])[$mailboxId] ?? self::emptyState();
	}

	/**
	 * @param string $noticeCode Which of the migration popups was shown: the rules tell one from the other.
	 */
	public function markPopupShown(int $mailboxId, string $noticeCode): void
	{
		if ($noticeCode === '')
		{
			return;
		}

		$this->saveMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::MIG_NOTICE_POPUP_SHOWN_PROPERTY_NAME,
			$noticeCode,
		);
	}

	/**
	 * Stores the moment the notice falls due again rather than a flag of its own.
	 *
	 * The popup is what is being postponed, so the mark that it has been through goes with the same
	 * write: a showing a person moved to another day is not the one showing that mailbox is due
	 * (AC-010, AC-011).
	 *
	 * @param int|null $from The moment the person asked to be reminded later; now by default.
	 * @return int The stored moment.
	 */
	public function remindLater(int $mailboxId, ?int $from = null): int
	{
		$moment = $this->resolveNextWorkingDayStart($from ?? time());

		$this->saveMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::MIG_NOTICE_REMIND_AFTER_PROPERTY_NAME,
			(string)$moment,
		);
		$this->saveMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::MIG_NOTICE_POPUP_SHOWN_PROPERTY_NAME,
			'',
		);

		return $moment;
	}

	public function markBannerDismissed(int $mailboxId): void
	{
		$this->saveMailboxOption(
			$mailboxId,
			MailEntityOptionsTable::MIG_NOTICE_BANNER_DISMISSED_PROPERTY_NAME,
			self::FLAG_VALUE,
		);
	}

	/**
	 * @return array{popupShown: string|null, remindUntil: int|null, bannerDismissed: bool}
	 */
	private static function emptyState(): array
	{
		return [
			'popupShown' => null,
			'remindUntil' => null,
			'bannerDismissed' => false,
		];
	}

	private function saveMailboxOption(int $mailboxId, string $propertyName, string $value): void
	{
		if ($mailboxId <= 0)
		{
			return;
		}

		$writtenAt = new DateTime();

		if (MailEntityOptionsTable::getCount($this->getMailboxOptionFilter($mailboxId, $propertyName)) > 0)
		{
			MailEntityOptionsTable::update(
				[
					'MAILBOX_ID' => $mailboxId,
					'ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
					'ENTITY_ID' => $mailboxId,
					'PROPERTY_NAME' => $propertyName,
				],
				[
					'DATE_INSERT' => $writtenAt,
					'VALUE' => $value,
				],
			);

			return;
		}

		MailEntityOptionsTable::insertIgnore(
			$mailboxId,
			$mailboxId,
			MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			$propertyName,
			$value,
			$writtenAt,
		);
	}

	private function getMailboxOptionFilter(int $mailboxId, string $propertyName): array
	{
		return [
			'=MAILBOX_ID' => $mailboxId,
			'=ENTITY_TYPE' => MailEntityOptionsTable::MAILBOX_TYPE_NAME,
			'=ENTITY_ID' => $mailboxId,
			'=PROPERTY_NAME' => $propertyName,
		];
	}

	private function resolveNextWorkingDayStart(int $from): int
	{
		$holidays = $this->getWeekHolidays();
		$moment = (int)strtotime('tomorrow', $from);

		for ($shift = 0; $shift < self::DAYS_IN_A_WEEK; $shift++)
		{
			if (!in_array((int)date('N', $moment), $holidays, true))
			{
				break;
			}

			$moment = (int)strtotime('+1 day', $moment);
		}

		return $moment;
	}

	/**
	 * Days off of the portal calendar; without the calendar module the working week is Monday to Friday.
	 *
	 * @return int[] ISO-8601 day numbers.
	 */
	private function getWeekHolidays(): array
	{
		$configured = ModuleManager::isModuleInstalled('calendar')
			? (string)Option::get('calendar', 'week_holidays', self::DEFAULT_WEEK_HOLIDAYS)
			: self::DEFAULT_WEEK_HOLIDAYS
		;

		$holidays = [];
		foreach (explode('|', $configured) as $dayCode)
		{
			$dayNumber = self::WEEK_DAY_NUMBERS[strtoupper(trim($dayCode))] ?? null;
			if ($dayNumber !== null)
			{
				$holidays[] = $dayNumber;
			}
		}

		return $holidays;
	}
}
