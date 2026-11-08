<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response;

use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\Field\Enum;
use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Field implements Arrayable, JsonSerializable
{
	public function __construct(
		public string $id,
		public string $title,
		public string $type,
		public bool $multiple,
		/** @var Enum[] */
		public ?array $enum = null,
	)
	{
	}

	public static function fromField(\Bitrix\Crm\Field $field): self
	{
		return new self(
			id: $field->getName(),
			title: $field->getTitle(),
			type: $field->getType(),
			multiple: $field->isMultiple(),
		);
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'title' => $this->title,
			'type' => $this->type,
			'multiple' => $this->multiple,
		];

		if ($this->enum !== null)
		{
			$result['enum'] = array_map(static fn (Enum $enum) => $enum->toArray(), $this->enum);
		}

		return $result;
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
