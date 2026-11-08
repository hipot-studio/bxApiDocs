<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internals;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Data\Internal\DeleteByFilterTrait;
use Bitrix\Main\ORM\Fields\DatetimeField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\ORM\Fields\StringField;
use Bitrix\Main\Type\DateTime;

/**
 * Senders whose auto-reply attempt is reserved within a period of the mailbox.
 *
 * The composite primary key, and not a check in PHP, is what keeps one address to one auto-reply attempt
 * per period: two letters of the same sender race each other, and only the successful insert owns the
 * right to send.
 *
 * The mailbox keeps here the period it is on and nothing else: the periods it has left behind are
 * cleared by the first reservation of the new one.
 */
final class VacationAutoReplyLogTable extends DataManager
{
	use DeleteByFilterTrait;

	public static function getTableName(): string
	{
		return 'b_mail_vacation_auto_reply_log';
	}

	/**
	 * Atomically reserves the address within the period. A conflict on the key means another process owns
	 * or has completed the attempt, which is the expected outcome and not an error.
	 *
	 * Writing the first reservation of a period is also what clears the periods the mailbox has left behind:
	 * the table holds a row per correspondent of a vacation, and one that is never cleared would keep
	 * every vacation of the mailbox for as long as the portal lives.
	 *
	 * @return bool True only for the process that inserted the reservation.
	 */
	public static function insertIgnore(int $mailboxId, int $periodId, string $address, DateTime $reservedAt): bool
	{
		self::deletePeriodsBefore($mailboxId, $periodId);

		$connection = self::getEntity()->getConnection();
		$sqlHelper = $connection->getSqlHelper();

		[$columns, $values] = $sqlHelper->prepareInsert(self::getTableName(),
			[
				'MAILBOX_ID' => $mailboxId,
				'PERIOD_ID' => $periodId,
				'ADDRESS' => self::normalizeAddress($address),
				'SENT_AT' => $reservedAt,
			]
		);

		$connection->queryExecute(
			$sqlHelper->getInsertIgnore(
				self::getTableName(),
				"($columns)",
				"VALUES($values)"
			)
		);

		return $connection->getAffectedRowsCount() > 0;
	}

	/**
	 * Drops what the mailbox attempted in the periods before this one. Whether a sender is handled is
	 * decided by the row of the period the setting holds now, and the number of a period never goes
	 * down, so a row of an earlier one is read by nobody ever again.
	 *
	 * The rows of the current period stay whatever the calendar says about it: a vacation whose end the
	 * user moves forward is the same period, and the people already reserved within it must not receive
	 * another attempt.
	 */
	public static function deletePeriodsBefore(int $mailboxId, int $periodId): void
	{
		if ($periodId <= VacationAutoReplyTable::FIRST_PERIOD_ID)
		{
			return;
		}

		self::deleteByFilter([
			'=MAILBOX_ID' => $mailboxId,
			'<PERIOD_ID' => $periodId,
		]);
	}

	/**
	 * The stored form of a sender address: the same person must not be answered twice only because
	 * the letters spelled the address differently.
	 */
	public static function normalizeAddress(string $address): string
	{
		return mb_strtolower(trim($address));
	}

	public static function getMap(): array
	{
		return [
			(new IntegerField('MAILBOX_ID'))->configurePrimary(),
			(new IntegerField('PERIOD_ID'))->configurePrimary(),
			(new StringField('ADDRESS'))->configurePrimary()->configureSize(255),
			(new DatetimeField('SENT_AT'))->configureRequired(),
		];
	}
}
