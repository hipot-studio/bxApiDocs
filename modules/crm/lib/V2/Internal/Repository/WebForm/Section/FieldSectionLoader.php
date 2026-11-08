<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Field;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\FieldTable;

/**
 * @internal
 */
final class FieldSectionLoader extends WebFormSectionLoader
{
	private const COLUMN_BY_FIELD = [
		Field::FIELD_NAME => 'CODE',
		Field::FIELD_TYPE => 'TYPE',
		Field::FIELD_LABEL => 'CAPTION',
		Field::FIELD_SORT => 'SORT',
		Field::FIELD_REQUIRED => 'REQUIRED',
		Field::FIELD_MULTIPLE => 'MULTIPLE',
		Field::FIELD_PLACEHOLDER => 'PLACEHOLDER',
		Field::FIELD_VALUE_TYPE => 'VALUE_TYPE',
		Field::FIELD_VALUE => 'VALUE',
	];

	public function getSectionName(): string
	{
		return WebForm::SECTION_FIELDS;
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, Field[]>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		$selectedFieldMap = array_fill_keys($selectedFields, true);
		$columns = ['FORM_ID', 'SORT', 'ID'];
		foreach ($selectedFields as $field)
		{
			$column = self::COLUMN_BY_FIELD[$field] ?? null;
			if ($column !== null)
			{
				$columns[] = $column;
			}
		}

		$query =
			FieldTable::query()
				->setSelect(array_values(array_unique($columns)))
				->whereIn('FORM_ID', $formIds)
				->setOrder([
					'FORM_ID' => 'ASC',
					'SORT' => 'ASC',
					'ID' => 'ASC',
				])
		;

		$fieldsByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['FORM_ID'];
			$fieldsByFormId[$formId][] = self::createField($row, $selectedFieldMap);
		}

		return $fieldsByFormId;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function createField(array $row, array $selectedFields): Field
	{
		return new Field(
			name: self::requiredString($row, $selectedFields, Field::FIELD_NAME),
			type: self::requiredString($row, $selectedFields, Field::FIELD_TYPE),
			label: self::nullableString($row, $selectedFields, Field::FIELD_LABEL),
			sort: self::integer($row, $selectedFields, Field::FIELD_SORT),
			required: self::boolean($row, $selectedFields, Field::FIELD_REQUIRED),
			multiple: self::boolean($row, $selectedFields, Field::FIELD_MULTIPLE),
			placeholder: self::nullableString($row, $selectedFields, Field::FIELD_PLACEHOLDER),
			valueType: self::nullableString($row, $selectedFields, Field::FIELD_VALUE_TYPE),
			value: self::nullableString($row, $selectedFields, Field::FIELD_VALUE),
		);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function requiredString(array $row, array $selectedFields, string $field): ?string
	{
		if (!isset($selectedFields[$field]))
		{
			return null;
		}

		return (string)($row[self::COLUMN_BY_FIELD[$field]] ?? '');
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function nullableString(array $row, array $selectedFields, string $field): ?string
	{
		if (!isset($selectedFields[$field]))
		{
			return null;
		}

		$value = $row[self::COLUMN_BY_FIELD[$field]] ?? null;

		return $value === null ? null : (string)$value;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function integer(array $row, array $selectedFields, string $field): ?int
	{
		if (!isset($selectedFields[$field]))
		{
			return null;
		}

		return (int)($row[self::COLUMN_BY_FIELD[$field]] ?? 0);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function boolean(array $row, array $selectedFields, string $field): ?bool
	{
		if (!isset($selectedFields[$field]))
		{
			return null;
		}

		$value = $row[self::COLUMN_BY_FIELD[$field]] ?? null;

		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
