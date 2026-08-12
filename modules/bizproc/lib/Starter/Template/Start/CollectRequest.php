<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Template\Start;

final readonly class CollectRequest
{
	public function __construct(
		public array $complexDocumentType,
		public int $eventType,
		public ?int $categoryId = null,
		public bool $onlyParameterized = false,
		public bool $useAutoExecuteBitmask = false,
		public bool $requireActive = true,
		public bool $excludeSystem = true,
	)
	{}
}
