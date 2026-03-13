<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Dto;

final class DirSortingData
{
	public function __construct(
		public readonly int $id,
		public readonly ?int $rootId,
		public readonly ?string $type,
		public readonly int $level,
		public readonly string $name,
		public readonly bool $isVirtual,
	)
	{
	}
}
