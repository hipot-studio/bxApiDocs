<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Order;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Exception\InvalidFilterException;
use Bitrix\Rest\V3\Exception\Validation\RequiredFieldInRequestException;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\Ordering\OrderStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;
use Bitrix\Timeman\V2\Infrastructure\Rest\Request\Record\ListRequest;
use Bitrix\Timeman\V2\Public\Provider\Params\ListParams;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\FieldsEnum;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Filter;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Select;
use Bitrix\Timeman\V2\Public\Provider\Params\Record\Sort;

final class RecordListRequestMapper
{
	/**
	 * @throws InvalidFilterException
	 * @throws RequiredFieldInRequestException
	 */
	public static function extractUserId(ListRequest $request): int
	{
		[$userId, $dateFrom, $dateTo] = self::resolveFilter($request->filter);

		return $userId;
	}

	/**
	 * @throws ArgumentException
	 * @throws InvalidFilterException
	 * @throws RequiredFieldInRequestException
	 */
	public static function mapToListParams(ListRequest $request): ListParams
	{
		[$userId, $dateFrom, $dateTo] = self::resolveFilter($request->filter);

		$pager = $request->pagination !== null
			? new Pager($request->pagination->getLimit(), $request->pagination->getOffset())
			: new Pager();

		return new ListParams(
			pager: $pager,
			filter: new Filter(
				userId: $userId,
				dateFrom: $dateFrom,
				dateTo: $dateTo,
			),
			sort: self::mapOrder($request->order),
			select: self::mapSelect($request->select),
		);
	}

	private static function mapSelect(?SelectStructure $select): Select
	{
		$map = [
			'id' => FieldsEnum::Id,
			'userId' => FieldsEnum::UserId,
			'startTime' => FieldsEnum::RecordedStartTimestamp,
			'endTime' => FieldsEnum::RecordedStopTimestamp,
			'duration' => FieldsEnum::RecordedDuration,
			'isApproved' => FieldsEnum::Approved,
		];

		$result = [];
		foreach ($select?->getList() ?? [] as $field)
		{
			$mappedField = $map[$field] ?? null;
			if ($mappedField === null)
			{
				continue;
			}

			$result[] = $mappedField->value;
		}

		return new Select(array_values(array_unique($result)));
	}

	private static function mapOrder(?OrderStructure $order): Sort
	{
		$map = [
			'id' => FieldsEnum::Id,
			'userId' => FieldsEnum::UserId,
			'startTime' => FieldsEnum::RecordedStartTimestamp,
			'endTime' => FieldsEnum::RecordedStopTimestamp,
			'duration' => FieldsEnum::RecordedDuration,
		];

		$result = [];
		foreach ($order?->getItems() ?? [] as $item)
		{
			$mappedField = $map[$item->getProperty()] ?? null;
			if ($mappedField === null)
			{
				continue;
			}

			$result[$mappedField->value] = strtolower($item->getOrder()->value) === Order::Desc->value
				? Order::Desc->value
				: Order::Asc->value;
		}

		return new Sort($result);
	}

	/**
	 * @return array{0: int, 1: ?int, 2: ?int}
	 * @throws InvalidFilterException
	 * @throws RequiredFieldInRequestException
	 */
	private static function resolveFilter(?FilterStructure $filter): array
	{
		$userId = null;
		$dateFrom = null;
		$dateTo = null;

		foreach (self::getFlatConditions($filter) as $condition)
		{
			$field = (string)$condition->getLeftOperand();
			$value = $condition->getRightOperand();

			if ($field === 'userId' && $condition->getOperator() === Operator::Equal)
			{
				$currentUserId = (int)$value;
				if ($userId !== null && $userId !== $currentUserId)
				{
					throw new InvalidFilterException('Multiple different values for filter.userId are not allowed.');
				}

				$userId = $currentUserId;

				continue;
			}

			if ($field !== 'startTime')
			{
				continue;
			}

			if ($condition->getOperator() === Operator::Between && is_array($value))
			{
				$dateFrom = self::convertDateTimeToTimestamp($value[0] ?? null);
				$dateTo = self::convertDateTimeToTimestamp($value[1] ?? null);

				continue;
			}

			$timestamp = self::convertDateTimeToTimestamp($value);
			if ($timestamp === null)
			{
				continue;
			}

			switch ($condition->getOperator())
			{
				case Operator::Equal:
					$dateFrom = $timestamp;
					$dateTo = $timestamp;
					break;

				case Operator::Greater:
					$dateFrom = $timestamp + 1;
					break;

				case Operator::GreaterOrEqual:
					$dateFrom = $timestamp;
					break;

				case Operator::Less:
					$dateTo = $timestamp - 1;
					break;

				case Operator::LessOrEqual:
					$dateTo = $timestamp;
					break;

				default:
					break;
			}
		}

		if (!$userId)
		{
			throw new RequiredFieldInRequestException('filter.userId');
		}

		return [$userId, $dateFrom, $dateTo];
	}

	/**
	 * @return Condition[]
	 */
	private static function getFlatConditions(?FilterStructure $filter): array
	{
		if ($filter === null)
		{
			return [];
		}

		$result = [];
		foreach ($filter->getConditions() as $condition)
		{
			if ($condition instanceof Condition)
			{
				$result[] = $condition;

				continue;
			}

			if ($condition instanceof FilterStructure)
			{
				$result = array_merge($result, self::getFlatConditions($condition));
			}
		}

		return $result;
	}

	private static function convertDateTimeToTimestamp(mixed $value): ?int
	{
		return $value instanceof DateTime ? $value->getTimestamp() : null;
	}
}
