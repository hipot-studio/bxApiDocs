<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\MainTool;

class PersonalState
{
	public function __construct(
		public readonly ?string $presetId,
		public readonly ?string $firstPage,
		public readonly ?array $sortData,
	)
	{
	}
}
