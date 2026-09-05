<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service;

use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internals\MailMessageMarkTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

class FavoritesService
{
	public const ERROR_MAILBOX_ACCESS_DENIED = 'MAILBOX_ACCESS_DENIED';
	public const ERROR_MESSAGE_NOT_IN_MAILBOX = 'MESSAGE_NOT_IN_MAILBOX';

	public function addToFavorites(int $userId, int $mailboxId, int $messageId): Result
	{
		$result = $this->guardMutation($userId, $mailboxId, $messageId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		MailMessageMarkTable::insertIgnore($mailboxId, $messageId, MailMessageMarkTable::CODE_FAVORITES, $userId);

		return $result;
	}

	public function removeFromFavorites(int $userId, int $mailboxId, int $messageId): Result
	{
		$result = $this->guardMutation($userId, $mailboxId, $messageId);
		if (!$result->isSuccess())
		{
			return $result;
		}

		MailMessageMarkTable::deleteMark($mailboxId, $messageId, MailMessageMarkTable::CODE_FAVORITES, $userId);

		return $result;
	}

	/**
	 * @param int[] $messageIds
	 * @return int[]
	 */
	public function getFavoriteMessageIds(int $userId, array $messageIds): array
	{
		if ($messageIds === [])
		{
			return [];
		}

		$rows = MailMessageMarkTable::getList([
			'select' => ['MESSAGE_ID'],
			'filter' => [
				'=USER_ID' => $userId,
				'=CODE' => MailMessageMarkTable::CODE_FAVORITES,
				'@MESSAGE_ID' => $messageIds,
			],
		])->fetchAll();

		return array_map('intval', array_column($rows, 'MESSAGE_ID'));
	}

	private function guardMutation(int $userId, int $mailboxId, int $messageId): Result
	{
		$result = new Result();

		// CAccess::GetUserCodesArray(0) would answer with the group codes of an anonymous visitor
		if ($userId <= 0)
		{
			return $result->addError(
				new Error('Access to the mailbox is denied.', self::ERROR_MAILBOX_ACCESS_DENIED),
			);
		}

		if (!$this->assertMailboxAccess($userId, $mailboxId))
		{
			return $result->addError(
				new Error('Access to the mailbox is denied.', self::ERROR_MAILBOX_ACCESS_DENIED),
			);
		}

		if (!$this->assertMessageBelongsToMailbox($messageId, $mailboxId))
		{
			return $result->addError(
				new Error('The message does not belong to the mailbox.', self::ERROR_MESSAGE_NOT_IN_MAILBOX),
			);
		}

		return $result;
	}

	private function assertMailboxAccess(int $userId, int $mailboxId): bool
	{
		return MailboxAccess::hasUserAccessToMailbox($mailboxId, $userId, true);
	}

	private function assertMessageBelongsToMailbox(int $messageId, int $mailboxId): bool
	{
		$message = MailMessageTable::getConsistentById($messageId, ['ID', 'MAILBOX_ID']);

		return $message !== null && (int)$message['MAILBOX_ID'] === $mailboxId;
	}

}
