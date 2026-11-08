<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Params;

final readonly class ApplicationFilter
{
	public function __construct(
		public bool $installed = false,
		public array $attributes = [],
		public ?ApplicationOrigin $origin = null,
		public bool $excludeImmune = false,
	)
	{
	}
}
