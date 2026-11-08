<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Category\Deal;

final readonly class DealCategoryPage
{
	public function __construct(
		public array $rows,
		public int $totalCount,
		public int $offset,
	)
	{
	}
}
