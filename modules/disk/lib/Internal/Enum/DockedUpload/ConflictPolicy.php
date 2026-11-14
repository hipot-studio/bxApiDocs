<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\DockedUpload;

/**
 * Conflict resolution policy for a folder-item of a docked upload.
 *
 * The wire-value (::value) is persisted to
 * b_disk_docked_upload_item.CONFLICT_POLICY and travels in DTOs.
 */
enum ConflictPolicy: string
{
	case ReplaceAll = 'replace_all';
	case Skip = 'skip';

	public static function default(): self
	{
		return self::Skip;
	}
}
