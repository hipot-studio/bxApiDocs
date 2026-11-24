<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\MainTool;

class CommonState
{
	public function __construct(
		public readonly bool $forced,
		public readonly ?string $presetId,
		public readonly ?string $firstPage,
		public readonly ?array $sortData,
	)
	{
	}
}
