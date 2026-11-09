<?php

namespace Bitrix\Sender\Internals\Dto;

/**
 * State of the segment snapshot as the edit form of the segment shows it.
 *
 * The defaults describe a segment that has no snapshot at all - an unsaved one - so the form of
 * a new segment is built from them.
 */
class SegmentSnapshotDTO
{
	/**
	 * @param string $lastBuildDate Formatted date of the last completed build, empty when there was none.
	 * @param bool $canRefresh False when the user may not edit, and when there is nothing to rebuild.
	 * @param string[] $activeFilterIds Filters a refresh rebuilds, and the only ones it reports counters for.
	 */
	public function __construct(
		public readonly string $lastBuildDate = '',
		public readonly bool $isBuilding = false,
		public readonly bool $isInQueue = false,
		public readonly bool $canRefresh = false,
		public readonly array $activeFilterIds = [],
	)
	{
	}

	public function toArray(): array
	{
		return [
			'LAST_BUILD_DATE' => $this->lastBuildDate,
			'IS_BUILDING' => $this->isBuilding,
			'IS_IN_QUEUE' => $this->isInQueue,
			'CAN_REFRESH' => $this->canRefresh,
			'ACTIVE_FILTER_IDS' => $this->activeFilterIds,
		];
	}
}
