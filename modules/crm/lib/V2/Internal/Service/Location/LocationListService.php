<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Location;

use Bitrix\Crm\V2\Internal\Integration\Sale\Location\LocationAdapter;
use Bitrix\Main\Filter\Filter;

final class LocationListService
{
	public const QUICK_SEARCH_FILTER_KEY = 'QUICK_SEARCH';

	public function __construct(private readonly LocationAdapter $adapter = new LocationAdapter())
	{
	}

	public function read(
		Filter $filter,
		array $savedSort,
		array $requestedSelect,
		string $languageId,
		int $limit,
		int $offset,
		bool $includeAllIds = false,
	): array
	{
		$rawFilter = $filter->getValue();
		$parentId = $this->exactInt($rawFilter['PARENT_ID'] ?? null) ?? 0;
		$find = trim((string)($rawFilter[self::QUICK_SEARCH_FILTER_KEY] ?? $rawFilter['FIND'] ?? ''));
		$scope = new EffectiveScope(
			$this->adapter->normalize($rawFilter, $parentId, $find, $languageId),
			$this->adapter->allowSort($savedSort, $languageId),
			$this->adapter->allowSelect($requestedSelect, $languageId),
			$this->adapter->nameRuntime($languageId),
		);

		return [
			'rows' => $this->adapter->fetchRows($scope, $limit, $offset),
			'count' => $this->adapter->fetchCount($scope),
			'allIds' => $includeAllIds ? $this->adapter->fetchAllIds($scope) : [],
		];
	}

	private function exactInt(mixed $value): ?int
	{
		if (is_int($value))
		{
			return $value >= 0 ? $value : null;
		}

		if (!is_string($value))
		{
			return null;
		}

		$value = trim($value);
		if (!preg_match('/^(?:0|[1-9]\d*)$/D', $value))
		{
			return null;
		}

		$parentId = (int)$value;

		return (string)$parentId === $value ? $parentId : null;
	}
}
