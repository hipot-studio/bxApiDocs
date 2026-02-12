<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Service\Dto;

class ActionsByRegionResultDto
{
	public function __construct(
		public readonly array $actionsForUpdate,
		public readonly array $namesForAddToOption,
		public readonly bool $hasRegion
	)
	{
	}
}
