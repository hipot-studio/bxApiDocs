<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure;

use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\InvalidRequestFieldTypeException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldsInRequestFilterPropertyException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Structure;

final class DetailPageLayoutListFilterStructure extends Structure
{
	private function __construct(
		private readonly int $categoryId,
		private readonly ?int $userId,
	)
	{
	}

	public static function create(mixed $value, string $dtoClass, Request $request): self
	{
		if (is_array($value) && array_is_list($value))
		{
			$value = self::normalizeConditionList($value);
		}

		if (!is_array($value) || ($value !== [] && array_is_list($value)))
		{
			throw new InvalidFilterException($value);
		}

		foreach (array_keys($value) as $fieldName)
		{
			if (!in_array($fieldName, ['categoryId', 'userId'], true))
			{
				throw new UnknownDtoPropertyException(self::getDto($dtoClass)->getShortName(), (string)$fieldName);
			}
		}

		if (!isset($value['categoryId']))
		{
			throw new RequiredFieldsInRequestFilterPropertyException('filter', ['categoryId']);
		}

		foreach ($value as $fieldName => $identifier)
		{
			if (!is_int($identifier))
			{
				throw new InvalidRequestFieldTypeException('filter.' . $fieldName, 'int');
			}
			if ($identifier < ($fieldName === 'categoryId' ? 0 : 1))
			{
				throw new InvalidFilterException([$fieldName => $identifier]);
			}
		}

		return new self($value['categoryId'], $value['userId'] ?? null);
	}

	private static function normalizeConditionList(array $value): array
	{
		$filter = [];
		foreach ($value as $condition)
		{
			if (!is_array($condition) || ($condition !== [] && array_is_list($condition)))
			{
				return $value;
			}

			foreach ($condition as $fieldName => $fieldValue)
			{
				$filter[$fieldName] = $fieldValue;
			}
		}

		return $filter;
	}

	public function getCategoryId(): int
	{
		return $this->categoryId;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}
}
