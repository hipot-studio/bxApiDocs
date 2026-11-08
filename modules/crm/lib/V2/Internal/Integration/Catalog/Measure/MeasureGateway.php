<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Catalog\Measure;

use Bitrix\Catalog\MeasureTable;
use Bitrix\Crm\V2\Internal\Model\Measure\Measure;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;

/**
 * @internal
 */
class MeasureGateway
{
	private const GRID_TO_ORM_FIELD_MAP = [
		'ID' => 'ID',
		'CODE' => 'CODE',
		'MEASURE_TITLE' => 'MEASURE_TITLE',
		'SYMBOL_RUS' => 'SYMBOL',
		'SYMBOL_INTL' => 'SYMBOL_INTL',
		'SYMBOL_LETTER_INTL' => 'SYMBOL_LETTER_INTL',
		'IS_DEFAULT' => 'IS_DEFAULT',
	];

	/**
	 * @param array<string, string> $sort
	 * @return Measure[]
	 */
	public function getList(array $sort = [], ?PageNavigation $pagination = null): array
	{
		Loader::requireModule('catalog');

		$query =
			MeasureTable::query()
				->setSelect(array_values(self::GRID_TO_ORM_FIELD_MAP))
				->setOrder($this->mapSort($sort))
		;

		if ($pagination !== null)
		{
			$query
				->setLimit($pagination->getLimit())
				->setOffset($pagination->getOffset())
			;
		}

		$measures = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$measures[] = $this->mapFromCatalogRow($row);
		}

		return $measures;
	}

	public function getCount(): int
	{
		Loader::requireModule('catalog');

		return MeasureTable::getCount();
	}

	public function getMaxId(): ?int
	{
		Loader::requireModule('catalog');

		$row =
			MeasureTable::query()
				->setSelect(['ID'])
				->setOrder(['ID' => 'DESC'])
				->setLimit(1)
				->fetch()
		;

		return $row === false ? null : (int)$row['ID'];
	}

	public function getCountAtOrBelow(int $upperBound): int
	{
		Loader::requireModule('catalog');

		return MeasureTable::getCount([
			'<=ID' => $upperBound,
		]);
	}

	/**
	 * @return int[]
	 */
	public function getNextIds(int $lastId, int $upperBound, int $limit): array
	{
		Loader::requireModule('catalog');

		if ($upperBound <= 0 || $limit <= 0)
		{
			return [];
		}

		$rows =
			MeasureTable::query()
				->setSelect(['ID'])
				->setFilter([
					'>ID' => $lastId,
					'<=ID' => $upperBound,
				])
				->setOrder(['ID' => 'ASC'])
				->setLimit($limit)
				->fetchAll()
		;

		return array_map(
			static fn(array $row): int => (int)$row['ID'],
			$rows,
		);
	}

	/**
	 * @param array<string, string> $fields
	 */
	public function update(int $id, array $fields): Result
	{
		Loader::requireModule('catalog');

		return MeasureTable::update($id, $this->mapToCatalogFields($fields));
	}

	public function delete(int $id): Result
	{
		Loader::requireModule('catalog');

		return MeasureTable::delete($id);
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function mapFromCatalogRow(array $row): Measure
	{
		return new Measure(
			id: (int)$row['ID'],
			code: (int)$row['CODE'],
			title: $this->mapNullableString($row['MEASURE_TITLE'] ?? null),
			symbolRus: $this->mapNullableString($row['SYMBOL'] ?? null),
			symbolIntl: $this->mapNullableString($row['SYMBOL_INTL'] ?? null),
			symbolLetterIntl: $this->mapNullableString($row['SYMBOL_LETTER_INTL'] ?? null),
			isDefault: ($row['IS_DEFAULT'] ?? null) === 'Y' || ($row['IS_DEFAULT'] ?? null) === true,
		);
	}

	/**
	 * @param array<string, string> $fields
	 * @return array<string, string>
	 */
	private function mapToCatalogFields(array $fields): array
	{
		$result = [];
		foreach ($fields as $fieldName => $value)
		{
			$ormField = self::GRID_TO_ORM_FIELD_MAP[$fieldName] ?? null;
			if ($ormField !== null)
			{
				$result[$ormField] = $value;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, string> $sort
	 * @return array<string, string>
	 */
	private function mapSort(array $sort): array
	{
		$result = [];
		foreach ($sort as $gridField => $direction)
		{
			$ormField = self::GRID_TO_ORM_FIELD_MAP[$gridField] ?? null;
			$direction = strtoupper($direction);
			if ($ormField === null || !in_array($direction, ['ASC', 'DESC'], true))
			{
				continue;
			}

			$result[$ormField] = $direction;
		}

		if (!isset($result['ID']))
		{
			$result['ID'] = 'ASC';
		}

		return $result;
	}

	private function mapNullableString(mixed $value): ?string
	{
		return $value === null ? null : (string)$value;
	}
}
