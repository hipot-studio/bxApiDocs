<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Service\Dto;

class DataForUpdateBitrixEngineDto
{
	public function __construct(
		public readonly array $forInsert,
		public readonly array $forDeactivate,
		public readonly array $forActivate
	)
	{
	}
}
