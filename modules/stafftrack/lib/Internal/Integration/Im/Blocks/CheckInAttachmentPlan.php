<?php

namespace Bitrix\StaffTrack\Internal\Integration\Im\Blocks;

final readonly class CheckInAttachmentPlan
{
	/**
	 * @param list<int> $galleryFileIds
	 * @param list<int> $fileBlockFileIds
	 * @param list<int> $fallbackFileIds
	 */
	public function __construct(
		public array $galleryFileIds,
		public array $fileBlockFileIds,
		public array $fallbackFileIds,
	)
	{
	}
}
