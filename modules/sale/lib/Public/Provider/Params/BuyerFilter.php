<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Provider\Params;

/**
 * Filter of the buyers list, in the vocabulary of the fields of the buyers grid.
 *
 * Conditions arrive the way the admin filter builds them — the operator prefix in front of the
 * field, `>=SUM_PAID` or `%USER.PERSONAL_PHONE` — and keep that form. A condition on a field outside
 * the list or with no value is dropped: an empty condition would narrow the list down to nothing.
 */
final class BuyerFilter
{
	/** The buyer the statistic row belongs to. */
	public const BUYER_ID = 'USER_ID';

	/** Columns of the statistic row: every one of them is compared as the row holds it. */
	public const STATISTIC_FIELDS = [
		'SUM_PAID',
		'COUNT_FULL_PAID_ORDER',
		'COUNT_PART_PAID_ORDER',
		'LAST_ORDER_DATE',
		'LID',
		'CURRENCY',
	];

	/** Groups of the buyer. */
	public const GROUP_FIELD = 'GROUP.GROUP_ID';

	/** Prefix of the fields of the buyer record. */
	public const USER_PREFIX = 'USER.';

	/** Fields of the buyer record the list is filtered by, without the prefix. */
	public const USER_FIELDS = [
		'LOGIN',
		'EMAIL',
		'PERSONAL_PHONE',
		'LAST_LOGIN',
		'DATE_REGISTER',
	];

	/** Numbers of the statistic row, zero for a buyer who has none. */
	private const METRIC_FIELDS = [
		'SUM_PAID',
		'COUNT_FULL_PAID_ORDER',
		'COUNT_PART_PAID_ORDER',
	];

	/** Operators an empty value of a column never passes. */
	private const COMPARISON_OPERATORS = ['', '=', '>', '>=', '<', '<=', '><', '@', '%', '%='];

	private const KEY = '/^([^A-Za-z]*)([A-Za-z_][\w.]*)$/';

	/** @var array<int, array{operator: string, field: string, value: mixed}> */
	private array $conditions = [];

	/**
	 * @param array $conditions conditions of the admin filter: operator and field => value
	 * @param string $search quick search string of the list, matched against the buyer
	 */
	public function __construct(array $conditions = [], private readonly string $search = '')
	{
		foreach ($conditions as $key => $value)
		{
			if (!is_string($key) || !preg_match(self::KEY, $key, $matches))
			{
				continue;
			}

			if ($value === null || $value === '' || $value === [] || !self::isAllowedField($matches[2]))
			{
				continue;
			}

			$this->conditions[] = [
				'operator' => $matches[1],
				'field' => $matches[2],
				'value' => $value,
			];
		}
	}

	/**
	 * @return array<int, array{operator: string, field: string, value: mixed}>
	 */
	public function getConditions(): array
	{
		return $this->conditions;
	}

	public function getSearch(): string
	{
		return trim($this->search);
	}

	/**
	 * Whether the filter reaches into the buyer record: by a field of it or by the quick search.
	 */
	public function usesBuyerRecord(): bool
	{
		if ($this->getSearch() !== '')
		{
			return true;
		}

		foreach ($this->conditions as $condition)
		{
			if (str_starts_with($condition['field'], self::USER_PREFIX))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a buyer who has no statistic cannot pass the filter.
	 *
	 * Such a buyer is listed with zeros and without a latest order, a site and a currency, so a
	 * condition on a number of the statistic that zero fails — «more than 3 orders», «paid at least
	 * 100» — or any comparison of the date, the site or the currency leaves them out. A negation or an
	 * operator this cannot judge is taken for one that keeps them.
	 */
	public function excludesBuyersWithoutStatistic(): bool
	{
		foreach ($this->conditions as $condition)
		{
			if (!in_array($condition['field'], self::STATISTIC_FIELDS, true))
			{
				continue;
			}

			$excludes = in_array($condition['field'], self::METRIC_FIELDS, true)
				? !self::isZeroMatched($condition['operator'], $condition['value'])
				: in_array($condition['operator'], self::COMPARISON_OPERATORS, true)
			;
			if ($excludes)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether zero passes the condition; a value of a shape the operator does not take counts as
	 * passing, so the doubt keeps the buyers without statistic in the list.
	 */
	private static function isZeroMatched(string $operator, mixed $value): bool
	{
		$numbers = array_values((array)$value);
		foreach ($numbers as $number)
		{
			if (!is_numeric($number))
			{
				return true;
			}
		}
		$numbers = array_map('floatval', $numbers);
		$single = count($numbers) === 1 ? $numbers[0] : null;

		return match ($operator)
		{
			'', '=', '@' => in_array(0.0, $numbers, true),
			'>' => $single === null || 0.0 > $single,
			'>=' => $single === null || 0.0 >= $single,
			'<' => $single === null || 0.0 < $single,
			'<=' => $single === null || 0.0 <= $single,
			'><' => count($numbers) !== 2 || ($numbers[0] <= 0.0 && 0.0 <= $numbers[1]),
			default => true,
		};
	}

	private static function isAllowedField(string $field): bool
	{
		if (
			$field === self::BUYER_ID
			|| $field === self::GROUP_FIELD
			|| in_array($field, self::STATISTIC_FIELDS, true)
		)
		{
			return true;
		}

		return
			str_starts_with($field, self::USER_PREFIX)
			&& in_array(substr($field, strlen(self::USER_PREFIX)), self::USER_FIELDS, true)
		;
	}
}
