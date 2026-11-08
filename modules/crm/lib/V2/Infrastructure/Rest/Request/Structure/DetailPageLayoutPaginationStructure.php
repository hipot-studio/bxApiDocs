<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidPaginationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\PaginationStructure;
use Bitrix\Rest\V3\Structure\Structure;

final class DetailPageLayoutPaginationStructure extends Structure
{
	private const ALLOWED_KEYS = ['limit', 'offset', 'page'];

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
		foreach (self::ALLOWED_KEYS as $fieldName)
		{
			if (!array_key_exists($fieldName, $value))
			{
				continue;
			}

			$integerValue = self::normalizeIntegerNumeric($value[$fieldName]);
			if ($integerValue === null)
			{
				throw new InvalidPaginationException([$fieldName => $value[$fieldName]]);
			}

			$value[$fieldName] = $integerValue;
		}
		self::validatePageOffset($value);

		return new self(PaginationStructure::create($value, $dtoClass, $request));
	}

	private static function normalizeIntegerNumeric(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value;
		}
		if (is_string($value) && preg_match('/^[+-]?\d+$/D', $value) === 1)
		{
			return self::normalizeIntegerString($value);
		}
		if ((!is_float($value) && !is_string($value)) || !is_numeric($value))
		{
			return null;
		}

		$numericValue = (float)$value;

		if (
			!is_finite($numericValue)
			|| floor($numericValue) !== $numericValue
			|| $numericValue < (float)PHP_INT_MIN
			|| $numericValue >= (float)PHP_INT_MAX
		)
		{
			return null;
		}

		return (int)$numericValue;
	}

	private static function normalizeIntegerString(string $value): ?int
	{
		$isNegative = str_starts_with($value, '-');
		$digits = ltrim(ltrim($value, '+-'), '0');
		$digits = $digits === '' ? '0' : $digits;
		$limit = $isNegative ? substr((string)PHP_INT_MIN, 1) : (string)PHP_INT_MAX;
		if (
			strlen($digits) > strlen($limit)
			|| (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)
		)
		{
			return null;
		}

		return (int)$value;
	}

	private static function validatePageOffset(array $value): void
	{
		$page = $value['page'] ?? 0;
		$limit = min($value['limit'] ?? PaginationStructure::DEFAULT_LIMIT, PaginationStructure::MAX_LIMIT);
		if ($page > 1 && $limit > 0 && $page - 1 > intdiv(PHP_INT_MAX, $limit))
		{
			throw new InvalidPaginationException(['page' => $page]);
		}
	}

	public function getLimit(): int
	{
		return $this->structure->getLimit();
	}

	public function getOffset(): int
	{
		return $this->structure->getOffset();
	}
}
