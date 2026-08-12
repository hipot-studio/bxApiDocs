<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Dto\FullReport;

use Bitrix\Main\Type\Contract\Arrayable;

final class UserReportsPage implements Arrayable
{
	public function __construct(
		public readonly UserReportsCollection $items,
		public readonly int $offset,
		public readonly int $limit,
		public readonly bool $hasMore,
		public readonly ?int $nextOffset,
	)
	{
	}

	public function toArray(): array
	{
		return [
			'items' => $this->items->toArray(),
			'offset' => $this->offset,
			'limit' => $this->limit,
			'hasMore' => $this->hasMore,
			'nextOffset' => $this->nextOffset,
		];
	}
}
