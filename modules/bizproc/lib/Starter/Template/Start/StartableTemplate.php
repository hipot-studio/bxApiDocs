<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Template\Start;

final readonly class StartableTemplate
{
	public function __construct(
		public int $id,
		public string $name,
		public string $description,
		public array $parameters,
	)
	{}

	public function hasParameters(): bool
	{
		return !empty($this->parameters);
	}
}
