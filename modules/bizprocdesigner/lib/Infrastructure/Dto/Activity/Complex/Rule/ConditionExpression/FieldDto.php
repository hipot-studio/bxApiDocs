<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression;

use Bitrix\Main\Validation\Rule\NotEmpty;use JsonSerializable;

class FieldDto implements JsonSerializable
{
	public function __construct(
		#[NotEmpty]
		public string $object,
		#[NotEmpty]
		public string $fieldId,
		public ?string $type = null,
		public ?int $multiple = null,
	) {
	}

	public static function createFromArray(array $data): self
	{
		return new self(
			$data['object'],
			$data['fieldId'],
			$data['type'] ?? null,
			isset($data['multiple']) ? (int)$data['multiple'] : null,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'object' => $this->object,
			'fieldId' => $this->fieldId,
			'type' => $this->type,
			'multiple' => $this->multiple,
		];
	}
}
