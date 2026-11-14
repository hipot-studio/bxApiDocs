<?php

namespace Bitrix\Crm\Service\Timeline\Navigation;

use Bitrix\Crm\Filter\Filter;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Filter\DateType;
use Bitrix\Main\UI\Filter\Options;

final class NativeFilterAdapter
{
	private const ALLOWED_FIELDS = ['ENTRY_CATEGORY_ID', 'AUTHOR_ID', 'CLIENT', 'ACTIVITY'];

	public static function isAllowedField(string $field): bool
	{
		return in_array($field, self::ALLOWED_FIELDS, true) || preg_match('/^CREATED(?:_|$)/D', $field) === 1;
	}

	public function normalizeCreatedPeriod(array $fields): array
	{
		if (!array_key_exists('CREATED_datesel', $fields))
		{
			return $fields;
		}
		$selection = $fields['CREATED_datesel'];
		if (!is_string($selection))
		{
			throw new \InvalidArgumentException('Invalid created period');
		}
		$source = $fields + ['CREATED_from' => '', 'CREATED_to' => ''];
		foreach (['CREATED_from', 'CREATED_to'] as $key)
		{
			$value = $source[$key];
			if (!is_string($value))
			{
				throw new \InvalidArgumentException('Invalid created boundary');
			}
			if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1)
			{
				$date = new Date($value, 'Y-m-d');
				if ($date->format('Y-m-d') !== $value)
				{
					throw new \InvalidArgumentException('Invalid created date');
				}
				$source[$key] = $date->toString();
			}
		}
		$resolved = [];
		if ($selection === DateType::RANGE)
		{
			foreach (['CREATED_from', 'CREATED_to'] as $key)
			{
				$value = $source[$key];
				if ($value === '')
				{
					continue;
				}
				// Absolute snapshots already contain stock server-time boundaries.
				if (str_contains($value, ':'))
				{
					$resolved[$key] = $value;
					continue;
				}
				if (DateTime::tryParse($value, Date::getFormat()) === null)
				{
					throw new \InvalidArgumentException('Invalid created date');
				}
				$edge = [];
				Options::calcDates('CREATED', [
					'CREATED_datesel' => DateType::RANGE, 'CREATED_from' => '', 'CREATED_to' => '', $key => $value,
				], $edge);
				$resolved[$key] = $edge[$key];
			}
		}
		elseif ($selection !== '' && $selection !== DateType::NONE)
		{
			Options::calcDates('CREATED', $source, $resolved);
			if ($resolved === [])
			{
				throw new \InvalidArgumentException('Invalid created period');
			}
		}
		$bounds = [];
		foreach (['CREATED_from', 'CREATED_to'] as $key)
		{
			if (($resolved[$key] ?? '') !== '')
			{
				$bounds[$key] = DateTime::tryParse($resolved[$key]);
				if ($bounds[$key] === null)
				{
					throw new \InvalidArgumentException('Invalid created boundary');
				}
			}
		}
		if (
			isset($bounds['CREATED_from'], $bounds['CREATED_to'])
			&& $bounds['CREATED_from']->getTimestamp() > $bounds['CREATED_to']->getTimestamp()
		)
		{
			throw new \InvalidArgumentException('Reversed created period');
		}
		foreach (array_keys($fields) as $key)
		{
			if (preg_match('/^CREATED_(?:datesel|from|to|days|month|months|quarter|year|years)$/D', $key) === 1)
			{
				unset($fields[$key]);
			}
		}
		if ($bounds !== [])
		{
			$fields['CREATED_datesel'] = DateType::RANGE;
			foreach ($bounds as $key => $date)
			{
				$fields[$key] = $date->toString();
			}
		}

		return $fields;
	}

	public function prepareRepositoryFilter(Filter $filter, NavigationSnapshot $snapshot): array
	{
		$values = $snapshot->native['fields'];
		if ($snapshot->native['find'] !== '')
		{
			$values['FIND'] = $snapshot->native['find'];
		}
		$filter->prepareListFilterParams($values);

		return $values;
	}

	public function export(array $fields, string $find = '', ?string $presetId = null): array
	{
		$fields = array_filter(
			$fields,
			static fn(mixed $value, string $key): bool => self::isAllowedField($key),
			ARRAY_FILTER_USE_BOTH,
		);

		return [
			'fields' => $fields === [] ? new \stdClass() : $fields,
			'find' => $find,
			'preset' => ['id' => $presetId, 'fingerprint' => null],
		];
	}
}
