<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Attachment;

use Bitrix\Disk;
use Bitrix\Mail\Helper\MailboxAccess;
use Bitrix\Mail\Internals\MailMessageAttachmentTable;
use Bitrix\Mail\MailMessageTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Response\Zip;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Error;
use Bitrix\Main\IO\Path;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class ArchiveService
{
	public const ERROR_INVALID_REQUEST = 'INVALID_REQUEST';
	public const ERROR_MAILBOX_ACCESS_DENIED = 'MAILBOX_ACCESS_DENIED';
	public const ERROR_ARCHIVE_UNAVAILABLE = 'ARCHIVE_UNAVAILABLE';

	private const MIN_FILES = 2;
	private const MAX_FILES = 1000;

	/** Spelled out: createByController() would name the action after the class path, not the api alias. */
	private const DOWNLOAD_ACTION = 'mail.api.attachment.downloadArchive';

	public function getMessageArchiveUrl(int $messageId, int $userId): Result
	{
		$result = new Result();
		$result->setData(['available' => false, 'archiveUrl' => null]);

		$access = $this->guardAccess($messageId, $userId);
		if (!$access->isSuccess())
		{
			return $result->addError($access->getErrors()[0]);
		}

		if (!$this->isDownloadAvailable() || !$this->isFileCountSupported($this->countAttachedFiles($messageId)))
		{
			return $result;
		}

		$url = UrlManager::getInstance()->create(self::DOWNLOAD_ACTION, ['messageId' => $messageId]);

		return $result->setData(['available' => true, 'archiveUrl' => (string)$url]);
	}

	public function buildMessageArchive(int $messageId, int $userId): Result
	{
		$result = new Result();

		$access = $this->guardAccess($messageId, $userId);
		if (!$access->isSuccess())
		{
			return $access;
		}

		if (!$this->isDownloadAvailable())
		{
			return $result->addError(
				new Error('Archive download is not available.', self::ERROR_ARCHIVE_UNAVAILABLE),
			);
		}

		$rows = $this->getAttachedFiles($messageId);
		if (!$this->isFileCountSupported(count($rows)))
		{
			return $result->addError(
				new Error('Archive is not available for this message.', self::ERROR_ARCHIVE_UNAVAILABLE),
			);
		}

		$archive = new Zip\Archive('archive' . date('y-m-d') . '.zip');
		$entryBuilder = new Zip\EntryBuilder();
		$usedNames = [];

		foreach ($rows as $row)
		{
			$entry = $entryBuilder->createFromFileId((int)$row['FILE_ID'], $this->resolveEntryName($row, $usedNames));
			if ($entry)
			{
				$archive->addEntry($entry);
			}
		}

		if ($archive->isEmpty())
		{
			return $result->addError(new Error('Archive is empty.', self::ERROR_ARCHIVE_UNAVAILABLE));
		}

		return $result->setData(['archive' => $archive]);
	}

	public function isDownloadAvailable(): bool
	{
		return Loader::includeModule('disk') && Disk\ZipNginx\Configuration::isEnabled();
	}

	private function guardAccess(int $messageId, int $userId): Result
	{
		$result = new Result();

		if ($messageId <= 0 || $userId <= 0)
		{
			return $result->addError(new Error('Invalid request.', self::ERROR_INVALID_REQUEST));
		}

		$message = MailMessageTable::getConsistentById($messageId, ['ID', 'MAILBOX_ID']);

		if (
			$message === null
			|| !MailboxAccess::hasUserAccessToMailbox((int)$message['MAILBOX_ID'], $userId, withSharedMailboxes: true)
		)
		{
			return $result->addError(new Error('Access denied.', self::ERROR_MAILBOX_ACCESS_DENIED));
		}

		return $result;
	}

	private function isFileCountSupported(int $count): bool
	{
		return $count >= self::MIN_FILES && $count <= $this->getMaxFiles();
	}

	private function getMaxFiles(): int
	{
		return (int)Option::get('mail', 'max_files_in_archive', (string)self::MAX_FILES);
	}

	private function countAttachedFiles(int $messageId): int
	{
		return MailMessageAttachmentTable::getCount(['=MESSAGE_ID' => $messageId, '>FILE_ID' => 0]);
	}

	private function getAttachedFiles(int $messageId): array
	{
		return MailMessageAttachmentTable::getList([
			'select' => ['ID', 'FILE_ID', 'FILE_NAME'],
			'filter' => ['=MESSAGE_ID' => $messageId, '>FILE_ID' => 0],
			'order' => ['ID' => 'ASC'],
			'limit' => $this->getMaxFiles() + 1,
		])->fetchAll();
	}

	/**
	 * @param array<string, true> $usedNames names already taken in this archive
	 */
	private function resolveEntryName(array $row, array &$usedNames): string
	{
		$name = $this->sanitizeFileName((string)$row['FILE_NAME']) ?: 'file-' . (int)$row['ID'];

		$candidate = $name;
		$copyIndex = 1;
		while (isset($usedNames[$candidate]))
		{
			$candidate = $this->addCopyIndex($name, $copyIndex++);
		}

		$usedNames[$candidate] = true;

		return $candidate;
	}

	/** The mod_zip file list is line based: a control character in a MIME name would inject an entry. */
	private function sanitizeFileName(string $name): string
	{
		$name = trim((string)Path::replaceInvalidFilename(str_replace("\0", '_', $name), static fn(): string => '_'));

		return ($name === '.' || $name === '..') ? '' : $name;
	}

	private function addCopyIndex(string $name, int $index): string
	{
		$dotPosition = mb_strrpos($name, '.');
		$stem = $dotPosition === false ? $name : mb_substr($name, 0, $dotPosition);
		$extension = $dotPosition === false ? '' : mb_substr($name, $dotPosition);

		return $stem . ' (' . $index . ')' . $extension;
	}
}
