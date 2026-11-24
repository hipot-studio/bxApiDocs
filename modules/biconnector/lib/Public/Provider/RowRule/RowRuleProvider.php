<?php

namespace Bitrix\BIConnector\Public\Provider\RowRule;

use Bitrix\BIConnector\Internal\Services\RowRule\RowRuleService;
use Bitrix\Main\Result;

/**
 * Reads row rules for the grid and the card in the model of the API.
 */
class RowRuleProvider
{
	public const PRESET_MY = 'my';
	public const PRESET_ACTIVE = 'active';
	public const PRESET_INACTIVE = 'inactive';

	public const DEFAULT_LIMIT = 20;
	public const MAX_LIMIT = 100;

	private const ORDER_FIELDS = ['id', 'dateCreate', 'dateModify', 'tableCode'];
	private const DEFAULT_ORDER = ['field' => 'dateModify', 'direction' => 'desc'];

	private readonly RowRuleService $service;

	public function __construct(?RowRuleService $service = null)
	{
		$this->service = $service ?? new RowRuleService();
	}

	/**
	 * @param array $request `filter` (`presetId`, `tableCode`, `createdById`, `updatedById`, `isUsed`), `order`,
	 *     `offset`, `limit` of the API `list` request.
	 * @param int $userId The current user: the author for the preset `my`.
	 *
	 * @return array{items: array[], total: int}
	 */
	public function getList(array $request, int $userId): array
	{
		$filter = $this->buildFilter(is_array($request['filter'] ?? null) ? $request['filter'] : [], $userId);
		if ($filter === null)
		{
			return ['items' => [], 'total' => 0];
		}

		return $this->service->getList(
			$filter,
			$this->buildOrder(is_array($request['order'] ?? null) ? $request['order'] : []),
			$this->normalizeOffset($request['offset'] ?? null),
			$this->normalizeLimit($request['limit'] ?? null),
		);
	}

	/**
	 * @param int $id
	 *
	 * @return Result Data: `rule` - the model of the API; ROW_RULE_NOT_FOUND when there is no such rule.
	 */
	public function get(int $id): Result
	{
		return $this->service->get($id);
	}

	/**
	 * @param array $filter
	 * @param int $userId
	 *
	 * @return array|null Filter of the repository; null when no rule can match.
	 */
	private function buildFilter(array $filter, int $userId): ?array
	{
		$presetId = (string)($filter['presetId'] ?? '');

		// no rule is assigned to cells yet, so none is in use
		if ($presetId === self::PRESET_ACTIVE || $this->normalizeBool($filter['isUsed'] ?? null) === true)
		{
			return null;
		}

		$tableCodes = $this->normalizeList(
			$filter['tableCode'] ?? null,
			'strval',
			static fn(string $code): bool => $code !== '',
		);
		$authorIds = $this->normalizeList(
			$filter['createdById'] ?? null,
			'intval',
			static fn(int $id): bool => $id > 0,
		);

		$editorIds = $this->normalizeList(
			$filter['updatedById'] ?? null,
			'intval',
			static fn(int $id): bool => $id > 0,
		);

		if ($presetId === self::PRESET_MY)
		{
			if ($userId <= 0 || ($authorIds !== [] && !in_array($userId, $authorIds, true)))
			{
				return null;
			}

			$authorIds = [$userId];
		}

		return [
			'tableCode' => $tableCodes,
			'createdById' => $authorIds,
			'updatedById' => $editorIds,
		];
	}

	private function normalizeList(mixed $value, callable $cast, callable $isValid): array
	{
		if ($value === null || $value === '' || $value === [])
		{
			return [];
		}

		$list = [];
		foreach ((array)$value as $item)
		{
			if (!is_scalar($item))
			{
				continue;
			}

			$item = $cast($item);
			if ($isValid($item))
			{
				$list[] = $item;
			}
		}

		return array_values(array_unique($list));
	}

	/**
	 * Values of a form post come as strings, so `"false"` and `"N"` mean false.
	 */
	private function normalizeBool(mixed $value): ?bool
	{
		if (is_bool($value))
		{
			return $value;
		}

		if (!is_scalar($value))
		{
			return null;
		}

		return match (strtolower((string)$value))
		{
			'1', 'true', 'y' => true,
			'0', 'false', 'n' => false,
			default => null,
		};
	}

	private function buildOrder(array $order): array
	{
		$field = $order['field'] ?? null;
		if (!in_array($field, self::ORDER_FIELDS, true))
		{
			return self::DEFAULT_ORDER;
		}

		$direction = is_string($order['direction'] ?? null) ? strtolower($order['direction']) : '';

		return [
			'field' => $field,
			'direction' => in_array($direction, ['asc', 'desc'], true) ? $direction : self::DEFAULT_ORDER['direction'],
		];
	}

	private function normalizeOffset(mixed $offset): int
	{
		return is_numeric($offset) ? max(0, (int)$offset) : 0;
	}

	private function normalizeLimit(mixed $limit): int
	{
		if (!is_numeric($limit))
		{
			return self::DEFAULT_LIMIT;
		}

		return min(self::MAX_LIMIT, max(1, (int)$limit));
	}
}
