<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

/**
 * The human-readable destination of one queue, as
 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$storageName} /
 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$targetFolderName} carry it.
 *
 * Built by {@see QueueTargetNameResolver}; every member is null when the destination
 * could not be resolved at all, or when the queue owner may not read it, so the client
 * simply renders no destination line instead of an empty one.
 *
 * The image and the kind of the storage travel with its title: the widget draws the
 * avatar of the storage beside the name, shaped by the kind, so that sections uploading
 * into different storages are told apart at a glance.
 */
final class QueueTargetNames
{
	public const STORAGE_KIND_USER = 'user';
	public const STORAGE_KIND_GROUP = 'group';
	public const STORAGE_KIND_COMMON = 'common';
	public const STORAGE_KIND_OTHER = 'other';

	public function __construct(
		public readonly ?string $storageName = null,
		public readonly ?string $targetFolderName = null,
		public readonly ?string $storageImageSrc = null,
		public readonly ?string $storageKind = null,
	)
	{
	}
}
