<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Repository\Catalog;

final class CatalogItemPager
{
	public const MAX_LIMIT = 200;
	public const DEFAULT_LIMIT = 50;

	private readonly int $offset;
	private readonly int $limit;

	public function __construct(int $offset = 0, int $limit = self::DEFAULT_LIMIT)
	{
		$this->offset = max(0, $offset);
		$this->limit = max(1, min(self::MAX_LIMIT, $limit));
	}

	public function getOffset(): int
	{
		return $this->offset;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}
}
