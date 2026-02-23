<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Connector\Schema\Dto;

class SchemaNodeDto
{
	public function __construct(
		public string $type,
		public ?string $key = null,
		public ?array $params = null,
	)
	{
	}
}