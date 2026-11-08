<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\PresetField;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\PresetFieldTable;

/**
 * @internal
 */
final class PresetFieldSectionLoader extends WebFormSectionLoader
{
	private const COLUMN_BY_FIELD = [
		PresetField::FIELD_ENTITY_NAME => 'ENTITY_NAME',
		PresetField::FIELD_FIELD_NAME => 'FIELD_NAME',
		PresetField::FIELD_VALUE => 'VALUE',
	];

	public function getSectionName(): string
	{
		return WebForm::SECTION_PRESET_FIELDS;
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, PresetField[]>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		$selectedFieldMap = array_fill_keys($selectedFields, true);
		$columns = ['FORM_ID'];
		foreach ($selectedFields as $field)
		{
			$column = self::COLUMN_BY_FIELD[$field] ?? null;
			if ($column !== null)
			{
				$columns[] = $column;
			}
		}

		$query =
			PresetFieldTable::query()
				->setSelect(array_values(array_unique($columns)))
				->whereIn('FORM_ID', $formIds)
				->setOrder([
					'FORM_ID' => 'ASC',
					'ENTITY_NAME' => 'ASC',
					'FIELD_NAME' => 'ASC',
					'VALUE' => 'ASC',
				])
		;

		$fieldsByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['FORM_ID'];
			$fieldsByFormId[$formId][] = new PresetField(
				entityName: self::string($row, $selectedFieldMap, PresetField::FIELD_ENTITY_NAME),
				fieldName: self::string($row, $selectedFieldMap, PresetField::FIELD_FIELD_NAME),
				value: self::string($row, $selectedFieldMap, PresetField::FIELD_VALUE),
			);
		}

		return $fieldsByFormId;
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function string(array $row, array $selectedFields, string $field): ?string
	{
		if (!isset($selectedFields[$field]))
		{
			return null;
		}

		return (string)($row[self::COLUMN_BY_FIELD[$field]] ?? '');
	}
}
