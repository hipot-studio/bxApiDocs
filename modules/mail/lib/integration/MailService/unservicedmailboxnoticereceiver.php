<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\MailService;

use Bitrix\Mail\Integration\Im\Notification;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Throwable;

/**
 * Takes the summary of one Mailhost operation - the mailboxes it left unserviced - and tells every suitable
 * administrator of the portal of it by a system notice of im.
 *
 * Suitable is by the rule of {@see SuitableAdministrators}. The one rule answers both whether there is anybody to tell
 * and whom im is asked for, so nobody it leaves out costs a refusal of im.
 */
final class UnservicedMailboxNoticeReceiver
{
	public const NAMED_ITEMS_MAX = 8;

	public const ERROR_CONTRACT_INVALID = 'MAIL_UNSERVICED_NOTICE_CONTRACT_INVALID';
	public const ERROR_NOBODY_TO_TELL = 'MAIL_UNSERVICED_NOTICE_NOBODY_TO_TELL';
	public const ERROR_NOT_DELIVERED = 'MAIL_UNSERVICED_NOTICE_NOT_DELIVERED';

	private const OPERATION_ID_MAX_LENGTH = 64;

	/**
	 * @param list<array{email: string, reason: string}> $named the pairs the summary names, in the order it names them
	 * @param array<string, int> $remainder the pairs left unnamed, by the word of their reason
	 */
	public function accept(string $operationId, array $named, array $remainder): Result
	{
		if (!$this->isContractValid($operationId, $named, $remainder))
		{
			return (new Result())->addError(new Error(
				'A valid summary of the mailboxes left unserviced is expected',
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

		$named = array_values($named);
		$delivered = true;
		foreach ($administrators as $administrator)
		{
			$delivered = $this->tell($administrator, $operationId, $named, $remainder) && $delivered;
		}

		if (!$delivered)
		{
			return (new Result())->addError(new Error(
				'The summary has not reached every suitable administrator',
				self::ERROR_NOT_DELIVERED,
			));
		}

		return new Result();
	}

	private function isContractValid(string $operationId, array $named, array $remainder): bool
	{
		if (
			$operationId === ''
			|| mb_strlen($operationId) > self::OPERATION_ID_MAX_LENGTH
			|| count($named) > self::NAMED_ITEMS_MAX
		)
		{
			return false;
		}

		foreach ($named as $item)
		{
			if (
				!is_array($item)
				|| !is_string($item['email'] ?? null)
				|| $item['email'] === ''
				|| !$this->isReason($item['reason'] ?? null)
			)
			{
				return false;
			}
		}

		foreach ($remainder as $reason => $count)
		{
			if (!$this->isReason($reason) || !is_int($count) || $count < 0)
			{
				return false;
			}
		}

		return true;
	}

	private function isReason(mixed $word): bool
	{
		return is_string($word) && in_array($word, Notification::MAILHOST_UNSERVICED_REASONS, true);
	}

	/**
	 * Every suitable administrator is told whatever im did with the ones before them: a summary is replaced by the
	 * next one of the operation, so telling one twice costs nothing.
	 */
	private function tell(int $administrator, string $operationId, array $named, array $remainder): bool
	{
		try
		{
			return Notification::notifyAdminAboutUnservicedMailhostMailboxes(
				$administrator,
				$operationId,
				$named,
				$remainder,
			);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	private function nobodyToTell(): Result
	{
		return (new Result())->addError(new Error(
			'The portal has no suitable administrator to tell of the mailboxes left unserviced',
			self::ERROR_NOBODY_TO_TELL,
		));
	}
}
