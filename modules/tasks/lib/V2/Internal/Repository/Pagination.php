<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository;

class Pagination
{
	public function __construct(
		public readonly int $limit = 50,
		public readonly int $offset = 0,
	)
	{
	}
}
