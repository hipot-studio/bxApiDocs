<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Starter\Dto;

final readonly class TriggerDescriptorDto
{
	/**
	 * @param array<string, mixed> $properties
	 */
	public function __construct(
		public string $triggerType,
		public ?string $title = null,
		public ?string $icon = null,
		public array $properties = [],
		public ?string $presetId = null,
	)
	{}
}
