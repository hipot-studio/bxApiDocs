<?php
declare(strict_types=1);

namespace Bitrix\Disk\Public\Contract\Relation;

/**
 * One object of the page the provider is asked about.
 *
 * A shared object of the page and the object the file really lives in are two different rows, so the
 * answer is keyed by objectId while the attachments are read by realObjectId.
 */
final class ObjectRef
{
	public function __construct(
		public readonly int $objectId,
		public readonly int $realObjectId,
		public readonly ?int $fileId,
	)
	{
	}
}
