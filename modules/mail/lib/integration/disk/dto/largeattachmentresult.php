<?php

declare(strict_types=1);

namespace Bitrix\Mail\Integration\Disk\Dto;

/**
 * Immutable outcome of placing a large attachment set on Disk and creating its serviceable public link.
 */
final class LargeAttachmentResult
{
	/**
	 * @param string $publicUrl Public URL of the serviceable link to embed into the message body.
	 * @param int|null $externalLinkId Disk external link id, or null when the storage does not expose one.
	 * @param int[] $objectIds Disk object ids that hold the uploaded files.
	 * @param int[] $fileIds Disk file ids included in the set.
	 * @param string $token Opaque identifier of the uploaded set for later deletion.
	 */
	public function __construct(
		public readonly string $publicUrl,
		public readonly ?int $externalLinkId,
		public readonly array $objectIds,
		public readonly array $fileIds,
		public readonly string $token,
	)
	{
	}
}
