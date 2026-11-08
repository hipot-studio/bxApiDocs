<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Response\Field;

use Bitrix\Main\Type\Contract\Arrayable;
use JsonSerializable;

final class Enum implements Arrayable, JsonSerializable
{
	public function __construct(
		public string $id,
		public string $value,
	)
	{
	}

	public static function tryFromArray(array $enum): ?self
	{
		$id = $enum['ID'] ?? null;
		if (!is_string($id) || empty($id))
		{
			return null;
		}

		$value = $enum['VALUE'] ?? null;
		if (!is_string($value) || empty($value))
		{
			return null;
		}

		return new self(
			id: $id,
			value: $value,
		);
	}

	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'value' => $this->value,
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
