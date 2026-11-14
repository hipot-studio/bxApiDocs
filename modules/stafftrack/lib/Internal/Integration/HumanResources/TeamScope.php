<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

/**
 * The team of a viewer without a filter - his direct scope - with whether every read of the structure behind it
 * succeeded and whether it was given from the cache of the team. A team that is not whole is given all the same, but
 * must not be kept.
 */
final class TeamScope
{
	/**
	 * @param int[] $userIds
	 */
	public function __construct(
		public readonly array $userIds,
		public readonly bool $isComplete,
		public readonly bool $isFromCache,
	)
	{
	}
}
