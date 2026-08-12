<?php

namespace Bitrix\Superset\Internal\Dto;

final class JwtStatus
{
	public function __construct(
		public readonly string $state,
		public readonly string $source,
		public readonly ?string $fingerprintSha256,
	)
	{
	}
}
