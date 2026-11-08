<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\ScheduledMessage;

use Bitrix\Mail\Service\Message\AttachmentContentId;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The files of a delayed letter as the sending takes them. Every attachment is named by the Content-ID its part
 * of the letter carries, and the Disk object every copy was made from is answered along with it: the body of the
 * snapshot marks an image pasted into the editor by that object, and only here, where both are at hand, can the
 * mark be turned into a reference to the part that holds the image.
 */
final class ScheduledMessageOutgoingAttachments
{
	public const ERROR_ATTACHMENT_UNAVAILABLE = 'attachment_unavailable';

	private \Closure $loadFile;

	public function __construct(private readonly string $hostname, ?callable $loadFile = null)
	{
		$this->loadFile = $loadFile !== null
			? \Closure::fromCallable($loadFile)
			: static fn (int $fileId): array|bool => \CFile::makeFileArray($fileId);
	}

	/**
	 * @param array<int, array<string, mixed>> $attachments
	 * @return Result Data: `attachments` as the command carries them and `contentIds` by source object.
	 */
	public function prepare(array $attachments): Result
	{
		$prepared = [];
		$contentIds = [];
		$totalSize = 0;
		foreach ($attachments as $attachment)
		{
			$file = ($this->loadFile)((int)($attachment['FILE_ID'] ?? 0));
			if (!is_array($file) || $file === [])
			{
				return $this->error(self::ERROR_ATTACHMENT_UNAVAILABLE, 'Attachment is not available.');
			}
			$totalSize += (int)($file['size'] ?? 0);

			$contentId = trim((string)($attachment['CONTENT_ID'] ?? ''));
			if ($contentId === '')
			{
				$contentId = AttachmentContentId::build($file, $this->hostname);
			}

			$sourceObjectId = (int)($attachment['SOURCE_OBJECT_ID'] ?? 0);
			if ($sourceObjectId > 0)
			{
				$contentIds[$sourceObjectId] = $contentId;
			}

			$prepared[] = [
				'ID' => $contentId,
				'NAME' => (string)($attachment['FILE_NAME'] ?? ''),
				'PATH' => (string)($file['tmp_name'] ?? ''),
				'CONTENT_TYPE' => (string)($file['type'] ?? 'application/octet-stream'),
			];
		}

		if (ScheduledMessageAttachmentService::isTotalSizeExceeded($totalSize))
		{
			return $this->error(
				ScheduledMessageAttachmentService::ERROR_ATTACHMENT_SIZE_LIMIT,
				'Attachment size limit exceeded.',
			);
		}

		return (new Result())->setData([
			'attachments' => $prepared,
			'contentIds' => $contentIds,
		]);
	}

	private function error(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
