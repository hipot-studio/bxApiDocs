<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig;

final class ConditionConfig
{
	public function __construct(
		public readonly string $field,
		public readonly string $operator,
		public readonly string|int $value,
		public readonly int $joiner = 0,
		public readonly string $object,
	) {}

	public static function fromArray(array $data): self
	{
		if (empty($data['field']) || empty($data['operator']))
		{
			throw new \InvalidArgumentException("Condition must have 'field' and 'operator'");
		}

		if (empty($data['object']))
		{
			throw new \InvalidArgumentException("Condition must have 'object' (activity ID)");
		}

		$joiner = $data['joiner'] ?? 0;
		if (is_string($joiner))
		{
			$joiner = ($joiner === 'Or' || $joiner === '1') ? 1 : 0;
		}

		return new self(
			field: $data['field'],
			operator: $data['operator'],
			value: $data['value'] ?? '',
			joiner: (int)$joiner,
			object: $data['object'],
		);
	}

	public function toArray(): array
	{
		return [
			'field' => $this->field,
			'operator' => $this->operator,
			'value' => $this->value,
			'joiner' => $this->joiner,
			'object' => $this->object,
		];
	}
}
