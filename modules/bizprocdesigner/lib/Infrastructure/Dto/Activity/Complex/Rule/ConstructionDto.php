<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule;

use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use JsonSerializable;

class ConstructionDto implements JsonSerializable
{
	public function __construct(
		public string $id,
		public ConstructionType $constructionType,
		public BaseExpressionDto $expression,
	)
	{

	}

	public static function fromArray(array $data): self
	{
		return new self(
			$data['id'],
			ConstructionType::from($data['type']),
			match ($data['type'])
			{
				ConstructionType::ACTION->value => ActionExpressionDto::fromArray($data['expression']),
				ConstructionType::OUTPUT->value => OutputExpressionDto::fromArray($data['expression']),
				ConstructionType::IF_CONDITION->value,
				ConstructionType::AND_CONDITION->value,
				ConstructionType::OR_CONDITION->value => ConditionExpressionDto::fromArray($data['expression']),
				default => throw new \InvalidArgumentException('Unknown construction type: ' . $data['type']),
			},
		);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'type' => $this->constructionType,
			'expression' => $this->expression,
		];
	}
}
