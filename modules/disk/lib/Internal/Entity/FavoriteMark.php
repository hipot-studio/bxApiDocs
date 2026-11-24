<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Entity;

/**
 * Personal favorite mark of a user on an object row of the Disk list.
 */
final class FavoriteMark
{
	public function __construct(
		public readonly int $id,
		public readonly int $userId,
		public readonly int $objectId,
	)
	{
	}
}
