<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Entity\MainTool;

class AvailablePreset
{
	public function __construct(
		public readonly string $id,
		public readonly bool $canApplyForAll,
	)
	{
	}
}
