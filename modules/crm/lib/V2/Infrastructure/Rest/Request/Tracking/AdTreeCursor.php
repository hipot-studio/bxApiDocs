<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class AdTreeCursor extends Structure
{
	private function __construct(private readonly string $value, private readonly int $limit)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (!is_array($value) || ($value['field'] ?? null) !== 'id'
			|| !is_string($value['value'] ?? null) || $value['value'] === ''
			|| strlen($value['value']) > 4096
			|| ($value['order'] ?? 'ASC') !== 'ASC'
			|| array_diff(array_keys($value), ['field', 'value', 'limit', 'order']) !== [])
		{
			throw self::invalid();
		}
		$limit = $value['limit'] ?? 50;
		if (!is_int($limit) || $limit < 1 || $limit > 100)
		{
			throw self::invalid();
		}

		return new self($value['value'], $limit);
	}

	public function getField(): string
	{
		return 'id';
	}

	public function getValue(): string
	{
		return $this->value;
	}

	public function getLimit(): int
	{
		return $this->limit;
	}

	private static function invalid(): RequestValidationException
	{
		return new RequestValidationException([new Error('Invalid advertising tree cursor.', 'cursor')]);
	}
}
