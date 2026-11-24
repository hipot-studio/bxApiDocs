<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Integration;

/**
 * A right asked of its module one user at a time: a user whose right cannot be read does not stop the
 * others from being read.
 */
final class PerUserRights
{
	/**
	 * @param int[] $userIds
	 * @param \Closure(int): bool $readRight
	 * @return array<int, bool> userId => the right
	 * @throws UnreadableRightsException when the right of some users could not be read
	 */
	public static function read(array $userIds, \Closure $readRight): array
	{
		$rights = [];
		$failures = [];
		foreach ($userIds as $userId)
		{
			try
			{
				$rights[$userId] = $readRight($userId);
			}
			catch (\Throwable $throwable)
			{
				$failures[$userId] = $throwable;
			}
		}

		if ($failures !== [])
		{
			throw new UnreadableRightsException($rights, $failures);
		}

		return $rights;
	}
}
