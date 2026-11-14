<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DockedUpload;

/**
 * The human-readable destination of one queue, as
 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$storageName} /
 * {@see \Bitrix\Disk\Internal\Dto\DockedUpload\QueueDto::$targetFolderName} carry it.
 *
 * Built by {@see QueueTargetNameResolver}; both members are null when the destination
 * could not be resolved at all, or when the queue owner may not read it, so the client
 * simply renders no destination line instead of an empty one.
 */
final class QueueTargetNames
{
	public function __construct(
		public readonly ?string $storageName = null,
		public readonly ?string $targetFolderName = null,
	)
	{
	}
}
