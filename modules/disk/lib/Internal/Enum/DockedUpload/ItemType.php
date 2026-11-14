<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Enum\DockedUpload;

/**
 * Kind of a docked upload element.
 *
 * The wire-value (::value) is persisted to
 * b_disk_docked_upload_item.ITEM_TYPE and travels in DTOs.
 */
enum ItemType: string
{
	case File = 'file';
	case Folder = 'folder';
}
