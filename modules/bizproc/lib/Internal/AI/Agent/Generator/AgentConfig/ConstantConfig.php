<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class ConstantConfig
{
	public function __construct(
		public readonly string $key,
		public readonly string $label,
		public readonly string $type,
		public readonly bool $multiple = false,
		public readonly bool $required = false,
		public readonly string|array|null $default = null,
		public readonly array $options = [],
		public readonly bool $showInWizard = true,
	) {}

	public static function fromArray(string $key, array $data): self
	{
		if (empty($data['label']) || empty($data['type']))
		{
			throw new \InvalidArgumentException("Constant '{$key}' must have 'label' and 'type'");
		}

		return new self(
			key: $key,
			label: $data['label'],
			type: $data['type'],
			multiple: $data['multiple'] ?? false,
			required: $data['required'] ?? false,
			default: $data['default'] ?? null,
			options: $data['options'] ?? [],
			showInWizard: $data['showInWizard'] ?? true,
		);
	}
}
