<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

final class CheckInAttachmentPlanner
{
	public const GALLERY_LIMIT = 10;
	public const FILE_BLOCK_LIMIT = 10;
	public const FALLBACK_LIMIT = 10;

	public static function maxAttachments(): int
	{
		return self::GALLERY_LIMIT + self::FILE_BLOCK_LIMIT + self::FALLBACK_LIMIT;
	}

	/**
	 * @param list<CheckInAttachment> $attachments
	 */
	public static function plan(array $attachments, bool $isFileBlockAvailable): CheckInAttachmentPlan
	{
		$mediaFileIds = [];
		$otherFileIds = [];

		foreach ($attachments as $attachment)
		{
			if ($attachment->isMedia)
			{
				$mediaFileIds[] = $attachment->diskFileId;
			}
			else
			{
				$otherFileIds[] = $attachment->diskFileId;
			}
		}

		$galleryFileIds = array_slice($mediaFileIds, 0, self::GALLERY_LIMIT);
		$fallbackFileIds = array_slice($mediaFileIds, self::GALLERY_LIMIT);
		$fileBlockFileIds = [];

		if ($isFileBlockAvailable)
		{
			$fileBlockFileIds = array_slice($otherFileIds, 0, self::FILE_BLOCK_LIMIT);
			$fallbackFileIds = array_merge($fallbackFileIds, array_slice($otherFileIds, self::FILE_BLOCK_LIMIT));
		}
		else
		{
			$fallbackFileIds = array_merge($fallbackFileIds, $otherFileIds);
		}

		return new CheckInAttachmentPlan($galleryFileIds, $fileBlockFileIds, $fallbackFileIds);
	}
}
