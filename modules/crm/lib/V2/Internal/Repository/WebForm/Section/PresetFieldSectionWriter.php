<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\PresetField;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\WebForm\Internals\PresetFieldTable;
use Bitrix\Main\Result;

/**
 * Writes the preset field values of a CRM form, mirroring PresetFieldSectionLoader.
 *
 * The collection is rewritten as a whole: a preset value has no identity of its own, so every
 * stored value is removed and the input is written instead. Preset values are not bound to the
 * fields of the form, and a value naming a field the form no longer has stays.
 *
 * @internal
 */
final class PresetFieldSectionWriter extends WebFormSectionWriter
{
	public function getSectionName(): string
	{
		return WebForm::SECTION_PRESET_FIELDS;
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		/** @var PresetField[] $presetFields */
		$presetFields = $changeSet->get($this->getSectionName());

		// The table has no row id of its own: FORM_ID is its primary, so one delete removes every
		// preset value of the form, the way legacy starts the rewrite.
		$result = new Result();
		$deleteResult = PresetFieldTable::delete(['FORM_ID' => $formId]);
		if (!self::copyErrors($deleteResult, $result))
		{
			return $result;
		}

		foreach ($presetFields as $presetField)
		{
			$addResult = PresetFieldTable::add(self::createRow($presetField, $formId));
			if (!self::copyErrors($addResult, $result))
			{
				return $result;
			}
		}

		return $result;
	}

	/**
	 * Builds the columns the preset value specifies. An unspecified attribute is left out and takes
	 * the creation default of the column.
	 *
	 * @return array<string, mixed>
	 */
	private static function createRow(PresetField $presetField, int $formId): array
	{
		$row = ['FORM_ID' => $formId];

		if ($presetField->entityName !== null)
		{
			$row['ENTITY_NAME'] = $presetField->entityName;
		}
		if ($presetField->fieldName !== null)
		{
			$row['FIELD_NAME'] = $presetField->fieldName;
		}
		if ($presetField->value !== null)
		{
			$row['VALUE'] = $presetField->value;
		}

		return $row;
	}
}
