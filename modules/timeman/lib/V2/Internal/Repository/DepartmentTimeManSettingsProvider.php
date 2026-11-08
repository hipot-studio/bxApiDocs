<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Repository;

final class DepartmentTimeManSettingsProvider
{
	public function __construct(private readonly ?\Closure $rowsLoader = null)
	{
	}

	/**
	 * @param int[] $departmentIds
	 * @param array<int, int> $parentByDepartment
	 * @return array{values: array<int, bool>, failedIds: array<int, true>}
	 */
	public function getForDepartments(array $departmentIds, array $parentByDepartment): array
	{
		$departmentIds = array_values(array_unique(array_filter(
			array_map('intval', $departmentIds),
			static fn (int $departmentId): bool => $departmentId > 0,
		)));
		if ($departmentIds === [])
		{
			return ['values' => [], 'failedIds' => []];
		}

		$rows = $this->rowsLoader !== null
			? ($this->rowsLoader)($departmentIds)
			: $this->loadRows($departmentIds);

		$rawValues = [];
		foreach ($rows as $row)
		{
			$departmentId = (int)($row['id'] ?? 0);
			if ($departmentId > 0)
			{
				$rawValues[$departmentId] = $row['value'] ?? null;
			}
		}

		$values = [];
		$failedIds = [];
		foreach ($departmentIds as $departmentId)
		{
			$this->resolveValue(
				$departmentId,
				$parentByDepartment,
				$rawValues,
				$values,
				$failedIds,
				[],
			);
		}

		return ['values' => $values, 'failedIds' => $failedIds];
	}

	private function loadRows(array $departmentIds): array
	{
		$iblockId = \COption::GetOptionInt('intranet', 'iblock_structure', 0);
		$field = \Bitrix\Main\UserFieldTable::query()
			->setSelect(['ID'])
			->where('ENTITY_ID', 'IBLOCK_' . $iblockId . '_SECTION')
			->where('FIELD_NAME', 'UF_TIMEMAN')
			->fetch()
		;
		$fieldId = (int)($field['ID'] ?? 0);
		if ($iblockId <= 0 || $fieldId <= 0)
		{
			throw new \RuntimeException('Timeman department settings are unavailable.');
		}

		$enumValues = [];
		$enumResult = \CUserFieldEnum::GetList([], ['USER_FIELD_ID' => $fieldId], false);
		while ($enum = $enumResult->Fetch())
		{
			$enumValues[(string)$enum['ID']] = strtoupper((string)$enum['XML_ID']);
		}

		$rows = [];
		$sectionResult = \CIBlockSection::GetList(
			['LEFT_MARGIN' => 'ASC'],
			[
				'IBLOCK_ID' => $iblockId,
				'ACTIVE' => 'Y',
				'ID' => $departmentIds,
			],
			false,
			['ID', 'UF_TIMEMAN'],
		);
		while ($section = $sectionResult->Fetch())
		{
			$value = $section['UF_TIMEMAN'] ?? null;
			if ($value === false || $value === null || $value === '' || $value === 0 || $value === '0')
			{
				$value = null;
			}
			else
			{
				$value = $enumValues[(string)$value] ?? strtoupper((string)$value);
			}

			$rows[] = [
				'id' => (int)$section['ID'],
				'value' => $value,
			];
		}

		return $rows;
	}

	private function resolveValue(
		int $departmentId,
		array $parentByDepartment,
		array $rawValues,
		array &$values,
		array &$failedIds,
		array $visited,
	): ?bool
	{
		if (array_key_exists($departmentId, $values))
		{
			return $values[$departmentId];
		}
		if (isset($failedIds[$departmentId]) || isset($visited[$departmentId]))
		{
			$failedIds[$departmentId] = true;

			return null;
		}
		if (!array_key_exists($departmentId, $rawValues))
		{
			$failedIds[$departmentId] = true;

			return null;
		}

		$rawValue = $rawValues[$departmentId];
		if ($rawValue === 'Y' || $rawValue === true)
		{
			return $values[$departmentId] = true;
		}
		if ($rawValue === 'N' || $rawValue === false)
		{
			return $values[$departmentId] = false;
		}
		if ($rawValue !== null)
		{
			$failedIds[$departmentId] = true;

			return null;
		}

		$parentId = (int)($parentByDepartment[$departmentId] ?? 0);
		if ($parentId <= 0)
		{
			return $values[$departmentId] = true;
		}

		$visited[$departmentId] = true;
		$parentValue = $this->resolveValue(
			$parentId,
			$parentByDepartment,
			$rawValues,
			$values,
			$failedIds,
			$visited,
		);
		if ($parentValue === null)
		{
			$failedIds[$departmentId] = true;

			return null;
		}

		return $values[$departmentId] = $parentValue;
	}
}
