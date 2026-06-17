<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\StorageItem;

use Bitrix\Bizproc\Public\Provider\Params\StorageItem\StorageItemFilter;

final class StorageItemQueryDto
{
	public function __construct(
		public readonly array $select = ['*'],
		public readonly ?StorageItemFilter $filter = null,
		public readonly array $order  = [],
		public readonly array $group  = [],
		public readonly ?int $limit  = null,
		public readonly ?int $offset = null,
	) {}
}
