<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\BoardStatistics;

use Bitrix\Main\Type\DateTime;

/** An object of b_disk_object as the search of the backfill border sees it: its id and when it was created. */
final class ObjectCreationProbe
{
	public function __construct(
		public readonly int $id,
		public readonly DateTime $createTime,
	)
	{
	}
}
