<?php

namespace Bitrix\StaffTrack\Internal\Integration\HumanResources;

final class IdNormalizer
{
	/**
	 * @param int[] $ids
	 * @return int[]
	 */
	public static function normalize(array $ids): array
	{
		return array_values(
			array_unique(
				array_filter(array_map('intval', $ids), static fn (int $id) => $id > 0),
			),
		);
	}
}
