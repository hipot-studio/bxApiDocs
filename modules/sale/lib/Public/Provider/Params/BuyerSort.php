<?php

declare(strict_types=1);

namespace Bitrix\Sale\Public\Provider\Params;

use Bitrix\Main\Provider\Params\Sort;

/**
 * Sorting of the buyers list by one column of the buyers grid.
 *
 * The grid sorts by a single column, so only the first one given is kept. A column the list
 * cannot be sorted by falls back to the date of the latest order and keeps the direction asked
 * for; the direction is ascending only when asked for, descending otherwise.
 */
final class BuyerSort extends Sort
{
	/**
	 * The column the list opens with. Every column of the statistic is served by an index of its
	 * own, and the latest orders first is what the list is opened for.
	 */
	public const DEFAULT_FIELD = 'LAST_ORDER_DATE';
	public const DEFAULT_DIRECTION = 'DESC';

	/** Columns of the statistic row the list may be sorted by. */
	public const STATISTIC_FIELDS = [
		'SUM_PAID',
		'COUNT_FULL_PAID_ORDER',
		'COUNT_PART_PAID_ORDER',
		'LAST_ORDER_DATE',
	];

	/** Columns of the buyer record the list may be sorted by. */
	public const USER_FIELDS = [
		'DATE_REGISTER',
		'LOGIN',
		'EMAIL',
		'NAME',
		'LAST_NAME',
		'SECOND_NAME',
		'PERSONAL_PHONE',
		'LAST_LOGIN',
		'PERSONAL_BIRTHDAY',
	];

	/**
	 * @param array $sort column => direction; empty for the order the list opens with
	 */
	public function __construct(array $sort = [])
	{
		if ($sort === [])
		{
			parent::__construct([self::DEFAULT_FIELD => self::DEFAULT_DIRECTION]);

			return;
		}

		$field = key($sort);
		$field = is_string($field) ? $field : '';
		if (!in_array($field, $this->getAllowedFields(), true))
		{
			$field = self::DEFAULT_FIELD;
		}

		$direction = current($sort);
		$direction = is_string($direction) && mb_strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC';

		parent::__construct([$field => $direction]);
	}

	public function getField(): string
	{
		return (string)key($this->sort);
	}

	/**
	 * @return 'ASC'|'DESC'
	 */
	public function getDirection(): string
	{
		return (string)current($this->sort);
	}

	/**
	 * Whether the list is ordered by a column of the buyer record.
	 */
	public function usesBuyerRecord(): bool
	{
		return in_array($this->getField(), self::USER_FIELDS, true);
	}

	protected function getAllowedFields(): array
	{
		return array_merge([BuyerFilter::BUYER_ID], self::STATISTIC_FIELDS, self::USER_FIELDS);
	}
}
