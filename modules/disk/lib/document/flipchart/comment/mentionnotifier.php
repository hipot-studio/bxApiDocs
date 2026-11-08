<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\Comment;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Command\Notification\NotifyThroughImSimpleSystemNotificationCommand;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

Loc::loadMessages(__FILE__);

/**
 * Tells the users mentioned in a comment about it with a system notification of the messenger.
 *
 * Only a registered user of the portal can be told: a guest has no portal identity, and the author
 * is not told about their own comment. The notification names the author and links the board on the
 * thread, but never carries the text of the comment: a mentioned user may have no access to the
 * board, and the link goes through the usual rights check of the portal. Nothing here changes the
 * outcome of the comment: a failure is written to the log and swallowed.
 *
 * The protected methods are the seams to the portal: the messenger, the board and the delivery
 * itself. Tests stand them in; production keeps the defaults.
 */
class MentionNotifier
{
	private const TITLE_MESSAGE = 'DISK_FLIPCHART_COMMENT_MENTION_NOTIFY_TITLE';
	private const MESSAGE_MESSAGE = 'DISK_FLIPCHART_COMMENT_MENTION_NOTIFY_MESSAGE';

	private const REGISTERED_USER_ID_PATTERN = '/^[1-9][0-9]{0,18}$/D';

	/**
	 * @param list<string> $mentions Mentions as they were received, repeats and guests included.
	 */
	public function notify(
		int $objectId,
		string $threadId,
		string $authorId,
		string $authorName,
		array $mentions,
	): void
	{
		try
		{
			$recipients = $this->selectRecipients($mentions, $authorId);
			if ($recipients === [] || !$this->isImAvailable())
			{
				return;
			}

			$file = $this->loadFile($objectId);
			if ($file === null)
			{
				$this->logException(new SystemException(
					sprintf('Board %d of a mention notification could not be loaded.', $objectId),
				));

				return;
			}

			$result = $this->send($this->createCommand($file, $threadId, $authorName, $recipients));
			if (!$result->isSuccess())
			{
				$this->logException(new SystemException(
					'Mention notification was not delivered: ' . implode('; ', $result->getErrorMessages()),
				));
			}
		}
		catch (\Throwable $exception)
		{
			$this->logException($exception);
		}
	}

	/**
	 * Registered users of the portal among the mentions, each once and never the author.
	 *
	 * @param list<string> $mentions
	 * @return list<int>
	 */
	private function selectRecipients(array $mentions, string $authorId): array
	{
		$authorUserId = self::isRegisteredUserId($authorId) ? (int)$authorId : 0;

		$recipients = [];
		foreach ($mentions as $userId)
		{
			if (!is_string($userId) || !self::isRegisteredUserId($userId))
			{
				continue;
			}

			$recipient = (int)$userId;
			if ($recipient !== $authorUserId)
			{
				$recipients[$recipient] = true;
			}
		}

		return array_keys($recipients);
	}

	/**
	 * The texts are resolved per recipient in the language of each; the link carries the thread as
	 * the element the board opens on.
	 *
	 * @param list<int> $recipients
	 */
	private function createCommand(
		File $file,
		string $threadId,
		string $authorName,
		array $recipients,
	): NotifyThroughImSimpleSystemNotificationCommand
	{
		$boardUrl = Driver::getInstance()->getUrlManager()->getUrlForViewBoardElement($file, $threadId, true);
		$replace = [
			'#AUTHOR#' => self::escapeForMessage($authorName),
			'#BOARD_LINK#' => '[url=' . $boardUrl . ']'
				. self::escapeForMessage((string)$file->getNameWithoutExtension())
				. '[/url]',
		];

		return new NotifyThroughImSimpleSystemNotificationCommand(
			title: static fn(?string $languageId = null): string => (string)Loc::getMessage(
				self::TITLE_MESSAGE,
				null,
				$languageId,
			),
			message: static fn(?string $languageId = null): string => (string)Loc::getMessage(
				self::MESSAGE_MESSAGE,
				$replace,
				$languageId,
			),
			recipients: $recipients,
		);
	}

	/**
	 * The message is a BB-code channel, and the name of the author and of the board are chosen by
	 * users. Brackets are replaced instead of entity-encoded, as the messenger does with file names:
	 * Text::parse() of `im` decodes `&#91;` back to `[` before the text reaches clients.
	 */
	private static function escapeForMessage(string $value): string
	{
		return str_replace(['[', ']'], ['(', ')'], htmlspecialcharsbx($value));
	}

	protected function send(NotifyThroughImSimpleSystemNotificationCommand $command): Result
	{
		return $command->run();
	}

	protected function loadFile(int $objectId): ?File
	{
		$file = File::loadById($objectId);

		return $file instanceof File ? $file : null;
	}

	protected function isImAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	private static function isRegisteredUserId(string $userId): bool
	{
		return preg_match(self::REGISTERED_USER_ID_PATTERN, $userId) === 1
			&& (string)(int)$userId === $userId;
	}

	private function logException(\Throwable $exception): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($exception);
		}
		catch (\Throwable)
		{
		}
	}
}
