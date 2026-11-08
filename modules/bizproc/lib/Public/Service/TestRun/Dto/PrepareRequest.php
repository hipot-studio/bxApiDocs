<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\TestRun\Dto;

final class PrepareRequest
{
	public function __construct(
		public readonly int $userId,
		public readonly int $templateId,
		public readonly string $blockId,
	)
	{
	}
}
