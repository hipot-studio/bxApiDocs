<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

use Bitrix\Disk\File;

final readonly class CheckInAttachment
{
	public function __construct(
		public int $diskFileId,
		public bool $isMedia,
		public ?File $diskFile = null,
	)
	{
	}
}
