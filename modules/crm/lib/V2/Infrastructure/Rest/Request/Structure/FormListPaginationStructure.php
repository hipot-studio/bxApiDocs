<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\Structure;

final class FormListPaginationStructure extends Structure
{
	public const MAX_LIMIT = 1000;

	private const ALLOWED_KEYS = [
		'limit',
		'offset',
	];

	private function __construct(
		private readonly PaginationStructure $structure,
	)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (
			!is_array($value)
			|| ($value !== [] && array_is_list($value))
			|| array_diff(array_keys($value), self::ALLOWED_KEYS) !== []
		)
		{
			throw new InvalidPaginationException($value);
		}

		if (
			isset($value['limit'])
			&& !self::isUnsignedIntegerInRange($value['limit'], 1, self::MAX_LIMIT)
		)
		{
			throw new InvalidPaginationException(['limit' => $value['limit']]);
		}

		if (
			isset($value['offset'])
			&& !self::isUnsignedIntegerInRange($value['offset'], 0, PHP_INT_MAX)
		)
		{
			throw new InvalidPaginationException(['offset' => $value['offset']]);
		}

		return new self(PaginationStructure::create($value, $dtoClass, $request));
	}

	public function getLimit(): int
	{
		return $this->structure->getLimit();
	}

	public function getOffset(): int
	{
		return $this->structure->getOffset();
	}

	private static function isUnsignedIntegerInRange(mixed $value, int $minimum, int $maximum): bool
	{
		if (is_int($value))
		{
			return $value >= $minimum && $value <= $maximum;
		}

		if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)
		{
			return false;
		}

		$normalizedValue = ltrim($value, '0');
		$normalizedValue = $normalizedValue === '' ? '0' : $normalizedValue;
		$maximumValue = (string)$maximum;
		if (
			strlen($normalizedValue) > strlen($maximumValue)
			|| (strlen($normalizedValue) === strlen($maximumValue) && strcmp($normalizedValue, $maximumValue) > 0)
		)
		{
			return false;
		}

		return (int)$normalizedValue >= $minimum;
	}
}
