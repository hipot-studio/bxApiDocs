<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\BoardStatistics;

use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\Type\Date;

final class BoardCreationIncrement
{
	public function __construct(
		public readonly int $userId,
		public readonly Date $month,
		public readonly int $count = 1,
	)
	{
		if ($userId <= 0)
		{
			throw new ArgumentOutOfRangeException('userId', 1);
		}
		if ($count <= 0)
		{
			throw new ArgumentOutOfRangeException('count', 1);
		}
	}
}
