<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Dto\Audit;

final class AuditObjectSnapshot
{
	public function __construct(
		public readonly ?int $storageId,
		public readonly ?string $storageName,
		public readonly string $name,
		public readonly string $nameSearch,
		public readonly ?string $path,
		public readonly ?string $parentId,
	)
	{
	}
}
