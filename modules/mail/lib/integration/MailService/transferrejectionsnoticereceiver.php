<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Throwable;

/**
 * Takes the summary of the refusals of a transfer to the mail of Bitrix24 and tells every suitable administrator of the
 * portal of it by a system notice of im, once the summary told before is taken off everyone who holds it.
 *
 * Suitable is by the rule of {@see SuitableAdministrators}: with nobody to tell nothing is taken off, so the summary
 * told before stays until there is somebody to tell the next one.
 *
 * Mailservice guarantees that state_conflict is sent only after it has repeated in consecutive waves.
 *
 * The codes of the owner of a mailbox, the refusal owner_locked and the hint owner_mismatch
 * ({@see Notification::TRANSFER_REJECTION_HINT_CODES}), are taken by the rule of any code of a mailbox: for an address,
 * a group and the tail, never for a domain. A summary of hints alone is a valid one.
 *
 * The summary is delivered once im takes it for one suitable administrator at least, the answer counting the ones im
 * refused it for or threw for; not delivered is only the summary im took for nobody.
 */
final class TransferRejectionsNoticeReceiver
{
	public const NAMED_ITEMS_MAX = 8;

	public const UNDELIVERED_DATA_KEY = 'undelivered';

	public const ERROR_CONTRACT_INVALID = 'MAIL_TRANSFER_REJECTIONS_CONTRACT_INVALID';
	public const ERROR_NOBODY_TO_TELL = 'MAIL_TRANSFER_REJECTIONS_NOBODY_TO_TELL';
	public const ERROR_NOT_DELIVERED = 'MAIL_TRANSFER_REJECTIONS_NOT_DELIVERED';
	public const ERROR_NOT_WITHDRAWN = 'MAIL_TRANSFER_REJECTIONS_NOT_WITHDRAWN';

	private const GROUP = 'group';
	private const GROUP_COUNT_MIN = 2;

	/**
	 * @param list<array{kind: string, name: string, code: string, count: int}> $lines the lines the summary names, in
	 * the order it names them; the count is the one of the mailboxes of a group, 1 for a line of another kind
	 * @param array<string, int> $remainder the pairs left unnamed, by the code of their refusal
	 */
	public function accept(array $lines, array $remainder): Result
	{
		return $this->deliver($lines, $remainder, false);
	}

	/**
	 * Replaces the summary for every suitable administrator with the push of the rewrite and without mail.
	 *
	 * @param list<array{kind: string, name: string, code: string, count: int}> $lines
	 * @param array<string, int> $remainder
	 */
	public function rewrite(array $lines, array $remainder): Result
	{
		return $this->deliver($lines, $remainder, true);
	}

	/** Takes the summary off every recipient, without requiring suitable administrators or intranet. */
	public function withdraw(): Result
	{
		try
		{
			if (Loader::includeModule('im') && Notification::withdrawTransferRejectionsNotice())
			{
				return new Result();
			}
		}
		catch (Throwable)
		{
			// A failed withdrawal can be retried on the next occasion.
		}

		return (new Result())->addError(new Error(
			'The summary could not be taken off its recipients',
			self::ERROR_NOT_WITHDRAWN,
		));
	}

	private function deliver(array $lines, array $remainder, bool $rewrite): Result
	{
		if (!$this->isContractValid($lines, $remainder))
		{
			return (new Result())->addError(new Error(
				'A valid summary of the refusals of a transfer is expected',
				self::ERROR_CONTRACT_INVALID,
			));
		}

		if (!Loader::includeModule('im') || !Loader::includeModule('intranet'))
		{
			return $this->nobodyToTell();
		}

		$administrators = (new SuitableAdministrators())->ids();
		if ($administrators === [])
		{
			return $this->nobodyToTell();
		}

		// The answer does not depend on the withdrawal: a summary not taken off stays with a former recipient alone,
		// as im replaces it by the tag for everyone told the new one.
		$this->withdraw();
		$lines = array_values($lines);
		$undelivered = 0;
		foreach ($administrators as $administrator)
		{
			if (!$this->tell($administrator, $lines, $remainder, $rewrite))
			{
				$undelivered++;
			}
		}

		if ($undelivered === count($administrators))
		{
			return (new Result())->addError(new Error(
				'The summary has reached no suitable administrator',
				self::ERROR_NOT_DELIVERED,
			));
		}

		return (new Result())->setData([self::UNDELIVERED_DATA_KEY => $undelivered]);
	}

	private function isContractValid(array $lines, array $remainder): bool
	{
		if ($lines === [] || count($lines) > self::NAMED_ITEMS_MAX)
		{
			return false;
		}

		foreach ($lines as $line)
		{
			if (!is_array($line) || !$this->isLine($line))
			{
				return false;
			}
		}

		foreach ($remainder as $code => $count)
		{
			if (!$this->isCode($code) || !is_int($count) || $count < 0)
			{
				return false;
			}
		}

		return true;
	}

	private function isLine(array $line): bool
	{
		$kind = $line['kind'] ?? null;
		$name = $line['name'] ?? null;
		$count = $line['count'] ?? null;

		return is_string($kind)
			&& in_array($kind, Notification::TRANSFER_REJECTION_KINDS, true)
			&& is_string($name)
			&& $name !== ''
			&& $this->isCode($line['code'] ?? null)
			&& ($kind !== 'domain' || in_array($line['code'], Notification::TRANSFER_REJECTION_DOMAIN_CODES, true))
			&& is_int($count)
			&& ($kind !== self::GROUP || $count >= self::GROUP_COUNT_MIN)
		;
	}

	private function isCode(mixed $code): bool
	{
		return is_string($code) && in_array($code, Notification::TRANSFER_REJECTION_CODES, true);
	}

	/** Every suitable administrator is told whatever im did with the ones before them. */
	private function tell(int $administrator, array $lines, array $remainder, bool $rewrite): bool
	{
		try
		{
			return Notification::notifyAdminAboutTransferRejections($administrator, $lines, $remainder, $rewrite);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	private function nobodyToTell(): Result
	{
		return (new Result())->addError(new Error(
			'The portal has no suitable administrator to tell of the refusals of a transfer',
			self::ERROR_NOBODY_TO_TELL,
		));
	}
}
