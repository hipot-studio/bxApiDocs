<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Disk\File;
use Bitrix\Mail\Helper\Message;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class ScheduledMessageAttachmentService
{
	public const ERROR_ATTACHMENT_INVALID = 'attachment_invalid';
	public const ERROR_ATTACHMENT_ACCESS_DENIED = 'attachment_access_denied';
	public const ERROR_ATTACHMENT_COPY_FAILED = 'attachment_copy_failed';
	public const ERROR_ATTACHMENT_SIZE_LIMIT = 'attachment_size_limit';

	public function __construct(private readonly ?int $maxAttachedFilesSize = null)
	{
	}

	/**
	 * Takes attachments as the form names them — `OBJECT_ID` is a Disk object — and answers with the rows of
	 * the letter, where `FILE_ID` is the copy in the storage of the portal and `SOURCE_OBJECT_ID` the object
	 * the copy was made from.
	 *
	 * @param array<int, array<string, mixed>> $attachments
	 * @param int[] $externalObjectIds Objects already validated as external links and excluded from MIME.
	 */
	public function copyForUser(int $userId, array $attachments, array $externalObjectIds = []): Result
	{
		$result = new Result();
		if ($attachments === [])
		{
			return $result->setData(['attachments' => []]);
		}

		if (!Loader::includeModule('disk'))
		{
			return $this->error(self::ERROR_ATTACHMENT_COPY_FAILED, 'Could not copy an attachment.');
		}

		$objectIds = array_values(array_unique(array_filter(array_map(
			static fn (array $attachment): int => (int)($attachment['OBJECT_ID'] ?? 0),
			$attachments,
		))));
		$filesById = [];
		foreach (File::loadBatchById($objectIds, ['STORAGE']) as $file)
		{
			$filesById[(int)$file->getId()] = $file;
		}

		$sourceFiles = [];
		$totalSize = 0;
		$externalObjectIds = array_fill_keys(array_map('intval', $externalObjectIds), true);
		foreach ($attachments as $attachment)
		{
			$objectId = (int)($attachment['OBJECT_ID'] ?? 0);
			$file = $filesById[$objectId] ?? null;
			if (!$file instanceof File || $file->isDeleted())
			{
				return $this->error(self::ERROR_ATTACHMENT_INVALID, 'Attachment is not available.');
			}

			$storage = $file->getStorage();
			if ($storage === null || !$file->canRead($storage->getSecurityContext($userId)))
			{
				return $this->error(self::ERROR_ATTACHMENT_ACCESS_DENIED, 'Access to attachment is denied.');
			}
			if ($this->containsHeaderControlCharacters($file->getName()))
			{
				return $this->error(self::ERROR_ATTACHMENT_INVALID, 'Attachment is not available.');
			}

			$sourceFiles[] = [$file, $attachment];
			if (!isset($externalObjectIds[$objectId]))
			{
				$totalSize += (int)$file->getSize();
			}
		}

		if (self::isTotalSizeExceeded($totalSize, $this->maxAttachedFilesSize))
		{
			return $this->error(self::ERROR_ATTACHMENT_SIZE_LIMIT, 'Attachment size limit exceeded.');
		}

		$copies = [];
		foreach ($sourceFiles as [$file, $attachment])
		{
			/** @var File $file */
			$contentId = !empty($attachment['IS_INLINE'])
				? sprintf('scheduled-%d-%s@bitrix24', $userId, bin2hex(random_bytes(8)))
				: null;
			$copyId = \CFile::CloneFile((int)$file->getFileId());
			if ($copyId === null)
			{
				$this->deleteCopies($copies);

				return $this->error(self::ERROR_ATTACHMENT_COPY_FAILED, 'Could not copy an attachment.');
			}

			$copies[] = [
				'FILE_ID' => $copyId,
				'SOURCE_OBJECT_ID' => (int)$file->getId(),
				'FILE_NAME' => $file->getName(),
				'CONTENT_ID' => $contentId,
				'IS_INLINE' => !empty($attachment['IS_INLINE']),
			];
		}

		return $result->setData(['attachments' => $copies]);
	}

	public static function isTotalSizeExceeded(int $totalSize, ?int $maxSize = null): bool
	{
		$maxSize ??= Message::getMaxAttachedFilesSize();

		return $maxSize > 0 && $maxSize <= ceil($totalSize / 3) * 4;
	}

	/** @param array<int, array<string, mixed>> $attachments */
	public function deleteCopies(array $attachments): void
	{
		foreach ($attachments as $attachment)
		{
			$fileId = (int)($attachment['FILE_ID'] ?? 0);
			if ($fileId > 0)
			{
				\CFile::Delete($fileId);
			}
		}
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}

	private function containsHeaderControlCharacters(string $value): bool
	{
		return (bool)preg_match('/[\x00-\x1F\x7F]/', $value);
	}
}
