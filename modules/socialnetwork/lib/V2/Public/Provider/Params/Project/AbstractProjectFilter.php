<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Public\Provider\Params\Project;

use Bitrix\Main\ORM\Query\Filter\ConditionTree;
use Bitrix\Main\ORM\Query\Filter\Helper;
use Bitrix\Main\Provider\Params\FilterInterface;
use Bitrix\Main\Search\Content;
use Bitrix\Main\Type\DateTime;

abstract class AbstractProjectFilter implements FilterInterface
{
	public function getAllowedFields(): array
	{
		return array_map(
			static fn(FieldsEnum $field) => $field->value,
			FieldsEnum::allowedForFilterList(),
		);
	}

	/**
	 * @param array<string, mixed> $filter
	 * @return array<string, mixed>
	 */
	protected function mapFilter(array $filter): array
	{
		$allowed = array_flip($this->getAllowedFields());
		$result = [];

		foreach ($filter as $key => $value)
		{
			[$operator, $publicField] = $this->extractOperator((string)$key);
			if (!isset($allowed[$publicField]))
			{
				continue;
			}

			$mappedField = FieldsEnum::tryFrom($publicField)?->toOrmField();
			if ($mappedField === null)
			{
				continue;
			}

			$result[$operator . $mappedField] = $value;
		}

		return $result;
	}

	/**
	 * @param array<string, mixed> $mappedFilter
	 */
	protected function buildConditionTree(array $mappedFilter): ConditionTree
	{
		$result = new ConditionTree();

		foreach ($mappedFilter as $key => $value)
		{
			[$operator, $field] = $this->extractOperator((string)$key);
			$this->applyCondition($result, $field, $operator, $value);
		}

		return $result;
	}

	protected function applyCondition(
		ConditionTree $filter,
		string $field,
		string $operator,
		mixed $value,
	): void
	{
		if ($operator === '')
		{
			$filter->where($field, '=', $value);

			return;
		}

		if ($operator === '@' && is_array($value))
		{
			$filter->whereIn($field, $value);

			return;
		}

		if ($operator === '!@' && is_array($value))
		{
			$filter->whereNotIn($field, $value);

			return;
		}

		if (($operator === '%' || $operator === '?') && is_string($value))
		{
			$filter->whereLike($field, '%' . $value . '%');

			return;
		}

		$filter->where($field, $operator, $value);
	}

	protected function extractOperator(string $input): array
	{
		if (preg_match('/^([^a-zA-Z]*)([a-zA-Z].*)$/', $input, $matches))
		{
			return [$matches[1], $matches[2]];
		}

		return ['', $input];
	}

	protected function extractUserIdFromEntitySelector(string $value): int
	{
		if (preg_match('/^U(\d+)$/', $value, $matches))
		{
			return (int)$matches[1];
		}

		return (int)$value;
	}

	protected function applyLegacyTextFilters(ConditionTree $filter, array $rawFilter): void
	{
		$name = trim((string)($rawFilter['NAME'] ?? ''));
		if ($name !== '')
		{
			$filter->whereLike('NAME', $name . '%');
		}

		$findValue = trim((string)($rawFilter['FIND'] ?? ''));
		if ($findValue === '')
		{
			return;
		}

		$searchToken = (
			Content::isIntegerToken($findValue)
				? Content::prepareIntegerToken($findValue)
				: Content::prepareStringToken($findValue)
		);

		if (Content::canUseFulltextSearch($searchToken, Content::TYPE_MIXED))
		{
			$filter->whereMatch('SEARCH_INDEX', Helper::matchAgainstWildcard($searchToken));
		}
	}

	/**
	 * @param array<string, mixed> $rawFilter
	 * @return array<string, mixed>
	 */
	protected function extractLegacyRangeFilters(array $rawFilter): array
	{
		$result = [];

		$idFrom = $this->extractInteger($rawFilter['ID_from'] ?? null);
		if ($idFrom !== null)
		{
			$result['>=ID'] = $idFrom;
		}

		$idTo = $this->extractInteger($rawFilter['ID_to'] ?? null);
		if ($idTo !== null)
		{
			$result['<=ID'] = $idTo;
		}

		$projectDateFrom = $this->createDateTime($rawFilter['PROJECT_DATE_from'] ?? null, true);
		if ($projectDateFrom !== null)
		{
			$result['>=PROJECT_DATE_START'] = $projectDateFrom;
		}

		$projectDateTo = $this->createDateTime($rawFilter['PROJECT_DATE_to'] ?? null, false);
		if ($projectDateTo !== null)
		{
			$result['<=PROJECT_DATE_FINISH'] = $projectDateTo;
		}

		return $result;
	}

	/**
	 * @param array<int, int[]> $idSets
	 * @param array<string, mixed> $result
	 * @return array<string, mixed>
	 */
	protected function applyIdSetIntersection(array $result, array $idSets): array
	{
		if (empty($idSets))
		{
			return $result;
		}

		$normalizedSets = array_map(
			static fn(array $ids): array => array_values(array_unique(array_map('intval', $ids))),
			$idSets,
		);

		$intersected = count($normalizedSets) === 1
			? $normalizedSets[0]
			: array_values(array_intersect(...$normalizedSets));

		if (empty($intersected))
		{
			$result['=ID'] = 0;
		}
		else
		{
			$result['@ID'] = $intersected;
		}

		return $result;
	}

	private function extractInteger(mixed $value): ?int
	{
		if ($value === '' || $value === null)
		{
			return null;
		}

		if (is_numeric($value))
		{
			return (int)$value;
		}

		return null;
	}

	private function createDateTime(mixed $value, bool $isStartOfDay): ?DateTime
	{
		if (!is_string($value) || trim($value) === '')
		{
			return null;
		}

		$time = $isStartOfDay ? '00:00:00' : '23:59:59';

		try
		{
			return new DateTime(trim($value) . ' ' . $time, 'd.m.Y H:i:s');
		}
		catch (\Exception)
		{
			return null;
		}
	}

	/**
	 * Extracts date range conditions from raw filter.
	 * Options::getFilter() returns date fields with _from/_to suffixes
	 * (e.g. DATE_CREATE_from => '19.03.2026'). This method converts them
	 * to >=DATE_CREATE / <=DATE_CREATE with Date objects for ORM.
	 *
	 * @param array<string, mixed> $rawFilter
	 * @return array<string, mixed>
	 */
	protected function extractDateFilters(array $rawFilter): array
	{
		$allowed = array_flip($this->getAllowedFields());
		$result = [];

		$dateRanges = [];
		foreach ($rawFilter as $key => $value)
		{
			if ($value === '' || $value === null)
			{
				continue;
			}

			if (str_ends_with($key, '_from'))
			{
				$dateRanges[substr($key, 0, -5)]['from'] = $value;
			}
			elseif (str_ends_with($key, '_to'))
			{
				$dateRanges[substr($key, 0, -3)]['to'] = $value;
			}
		}

		foreach ($dateRanges as $fieldName => $range)
		{
			$enum = FieldsEnum::fromOrmField($fieldName);
			if ($enum === null || !isset($allowed[$enum->value]))
			{
				continue;
			}

			$ormField = $enum->toOrmField();

			if (isset($range['from']))
			{
				$result['>=' . $ormField] = new DateTime($range['from'] . ' 00:00:00', 'd.m.Y H:i:s');
			}

			if (isset($range['to']))
			{
				$result['<=' . $ormField] = new DateTime($range['to'] . ' 23:59:59', 'd.m.Y H:i:s');
			}
		}

		return $result;
	}
}
