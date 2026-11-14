<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * The section of access of a card: what the reader may do with the object and how it is shared.
 *
 * A field that is not shown is left uninitialised and absent from the answer. A folder has no
 * {@see $canDownload}, as it has no fields of content.
 *
 * Property order is the order of the published answer.
 */
final class AccessSectionDto extends Dto
{
	public bool $canRead;

	/** Present only for files and equal to the availability of the content of the same card. */
	public bool $canDownload;

	public SharingDto $internalSharing;

	public PublicAccessDto $publicAccess;
}
