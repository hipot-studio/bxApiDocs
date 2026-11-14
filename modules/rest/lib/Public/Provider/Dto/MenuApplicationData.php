<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto;

final readonly class MenuApplicationData
{
	public function __construct(
		public int $id,
		public string $code,
		public string $clientId,
		public string $menuName,
		public bool $hasApplicationPage,
	)
	{
	}
}
