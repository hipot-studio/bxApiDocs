<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpression\FieldDto;
use Bitrix\Main\Validation\Rule\NotEmpty;
use Bitrix\Main\Validation\Rule\Recursive\Validatable;

class ConditionExpressionDto extends BaseExpressionDto
{
	public function __construct(
		#[Validatable]
		public ?FieldDto $field = null,
		#[NotEmpty]
		public ?string $operator = null,
		public ?string $value = null,
	)
	{

	}

	public static function fromArray(array $data): self
	{
		return new self(
			is_array($data['field']) ? FieldDto::createFromArray($data['field']) : null,
			$data['operator'] ?? null,
			$data['value'] ?? null,
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'field' => $this->field?->jsonSerialize(),
			'operator' => $this->operator,
			'value' => $this->value,
		];
	}
}
