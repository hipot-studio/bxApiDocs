<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\Ads\Internals\AdsFormLinkTable;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Field;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebFormChangeSet;
use Bitrix\Crm\WebForm\Booking;
use Bitrix\Crm\WebForm\Internals\FieldDependenceTable;
use Bitrix\Crm\WebForm\Internals\FieldTable;
use Bitrix\Crm\WebForm\Internals\FormFieldMappingTable;
use Bitrix\Main\Result;

/**
 * Writes the fields of a CRM form, mirroring FieldSectionLoader.
 *
 * Fields are identified by their name: a name already stored by the form updates its row, a new
 * name adds one, and a stored name missing from the input is removed. Renaming a field is
 * therefore removing one field and creating another. A name the form happens to hold twice ends up
 * held once: the write keeps one row per name and removes the rest.
 *
 * @internal
 */
final class FieldSectionWriter extends WebFormSectionWriter
{
	private const SORT_STEP = 10;

	/** Identifiers one removal statement carries; the ones read past it go into the next. */
	private const DELETE_PORTION = 500;

	/**
	 * The order the rows of a form stand in. The first row of a code is the one a write keeps, so
	 * every read about a stored field takes this order to be looking at the same row.
	 */
	private const STORED_ROW_ORDER = ['SORT' => 'ASC', 'ID' => 'ASC'];

	public function getSectionName(): string
	{
		return WebForm::SECTION_FIELDS;
	}

	/**
	 * Tells whether the form ends up holding a booking field once this input is written.
	 *
	 * Asked here because the answer is about the rows, not about the request: an attribute the input
	 * leaves unspecified keeps the value the stored row has, so a field sent without a type is of
	 * whatever type the form already holds it as. Read off the input alone, an existing booking field
	 * sent without its type would read as an ordinary one and switch the form out of booking.
	 *
	 * Only the codes whose type the input does not carry are asked about, and only when none of the
	 * specified types answers the question already: an input naming its types costs no query at all.
	 *
	 * The stored type of a code is the type of the row this write keeps - the first one of that code
	 * in the order below. A form may hold one code twice, and the write keeps the first row and
	 * removes the rest, so asking whether the form holds any booking row at all would raise the flag
	 * for a row that is about to leave.
	 *
	 * @param Field[] $fields
	 */
	public static function composesBookingForm(int $formId, array $fields): bool
	{
		$unspecifiedCodes = [];
		foreach ($fields as $field)
		{
			if ($field->type === Booking::RESOURCE_FIELD_TYPE)
			{
				return true;
			}

			$code = (string)$field->name;
			if ($field->type === null && $code !== '')
			{
				$unspecifiedCodes[] = $code;
			}
		}

		if ($formId <= 0 || $unspecifiedCodes === [])
		{
			return false;
		}

		$rows = FieldTable::query()
			->setSelect(['CODE', 'TYPE'])
			->where('FORM_ID', $formId)
			->whereIn('CODE', $unspecifiedCodes)
			->setOrder(self::STORED_ROW_ORDER)
			->fetchAll()
		;

		$keptTypes = [];
		foreach ($rows as $row)
		{
			$code = (string)$row['CODE'];
			$keptTypes[$code] ??= (string)$row['TYPE'];
		}

		return in_array(Booking::RESOURCE_FIELD_TYPE, $keptTypes, true);
	}

	protected function writeTouched(int $formId, WebFormChangeSet $changeSet): Result
	{
		$result = new Result();
		/** @var Field[] $fields */
		$fields = $changeSet->get($this->getSectionName());
		$unusedRowsByCode = self::loadStoredRows($formId);

		$writtenCodes = [];
		foreach ($fields as $index => $field)
		{
			$code = (string)$field->name;
			$row = self::createRow($field, $index);
			$storedRow = isset($unusedRowsByCode[$code])
				? array_shift($unusedRowsByCode[$code])
				: null
			;
			if ($storedRow === null)
			{
				$row['FORM_ID'] = $formId;
				$writeResult = FieldTable::add($row);
			}
			else
			{
				// Attributes of a stored field are updated, its service data survives. FieldTable
				// resets ITEMS on every update that does not carry them.
				$row['ITEMS'] = $storedRow['ITEMS'];
				$writeResult = FieldTable::update($storedRow['ID'], $row);
			}

			if (!self::copyErrors($writeResult, $result))
			{
				return $result;
			}

			$writtenCodes[$code] = true;
		}

		return self::removeUnusedRows($formId, $unusedRowsByCode, $writtenCodes, $result);
	}

	/**
	 * Removes the stored rows the input left unused and, for the fields that are gone from the form
	 * altogether, the dependency rules pointing at them.
	 *
	 * A row is left unused for one of two reasons, and they are told apart on purpose. A code the
	 * input no longer carries is a field removed from the form: its rules go with it. A code the
	 * input does carry can leave rows behind as well - the storage has no unique key over the code
	 * of a field, so a form may hold the same code twice, and the write keeps one row per name, the
	 * identity the contract stands on. Such a field is not removed from the form, and its rules stay.
	 *
	 * @param array<string, array<array{ID: int, ITEMS: array}>> $unusedRowsByCode
	 * @param array<string, true> $writtenCodes
	 */
	private static function removeUnusedRows(
		int $formId,
		array $unusedRowsByCode,
		array $writtenCodes,
		Result $result,
	): Result
	{
		$unusedIds = [];
		$removedCodes = [];
		foreach ($unusedRowsByCode as $code => $unusedRows)
		{
			$code = (string)$code;
			foreach ($unusedRows as $unusedRow)
			{
				$unusedIds[] = $unusedRow['ID'];
			}

			if ($unusedRows !== [] && !isset($writtenCodes[$code]))
			{
				$removedCodes[] = $code;
			}
		}

		// The rows leave in sets rather than one by one: how many of them there are is decided by
		// what the form stored earlier - a form of hundreds of fields cleared by one request would
		// otherwise cost a statement per field. FieldTable declares no delete handler, so nothing
		// depends on the rows leaving separately; a failed statement is raised by exception, the way
		// DataManager::delete() raises it.
		foreach (array_chunk($unusedIds, self::DELETE_PORTION) as $portion)
		{
			FieldTable::deleteByFilter(['@ID' => $portion]);
		}

		if ($removedCodes === [])
		{
			return $result;
		}

		self::deleteAdFieldMappingsOfRemovedFields($formId, $removedCodes);

		return self::deleteRulesOfRemovedFields($formId, $removedCodes);
	}

	/**
	 * Removing a field removes the ad field mappings pointing at it, the way it removes the
	 * dependency rules.
	 *
	 * A mapping names the field of the form an ad field is written into, and the domain validation
	 * refuses a mapping naming a field the form does not end up with. A request carrying the fields
	 * alone would otherwise leave exactly that state stored: a mapping pointing at nothing, which no
	 * later request could have created. The rows of the mappings belong to CRM and carry no
	 * subscription of their own - the link does - so they are removed here directly, while the links
	 * themselves stay with the mechanism owning them.
	 *
	 * @param string[] $removedCodes
	 */
	private static function deleteAdFieldMappingsOfRemovedFields(int $formId, array $removedCodes): void
	{
		$linkIds = array_map(
			static fn (array $row): int => (int)$row['ID'],
			AdsFormLinkTable::query()
				->setSelect(['ID'])
				->where('WEBFORM_ID', $formId)
				->fetchAll()
			,
		);
		if ($linkIds === [])
		{
			return;
		}

		FormFieldMappingTable::deleteByFilter([
			'@FORM_LINK_ID' => $linkIds,
			'@CRM_FIELD_KEY' => $removedCodes,
		]);
	}

	/**
	 * Removing a field removes the dependency rules referring to it, the way the legacy dependency
	 * filter did it by rebuilding itself from the stored field composition.
	 *
	 * The rules of the form are read in a stream and removed in sets: how many of them there are is
	 * decided by what the form stored earlier, not by the input at hand, so neither the selection
	 * nor the number of statements may follow it. FieldDependenceTable declares no delete handler,
	 * so nothing depends on the rows leaving one by one; a failed statement is raised by exception,
	 * the way DataManager::delete() raises it.
	 *
	 * @param string[] $removedCodes
	 */
	private static function deleteRulesOfRemovedFields(int $formId, array $removedCodes): Result
	{
		$removed = array_fill_keys($removedCodes, true);
		// Field codes of a rule are stored as strings while the ORM map declares them integers, so
		// they are matched here instead of in a filter that would cast them.
		$rows = FieldDependenceTable::query()
			->setSelect(['ID', 'IF_FIELD_CODE', 'DO_FIELD_CODE'])
			->where('FORM_ID', $formId)
			->exec()
		;

		$ids = [];
		while ($row = $rows->fetch())
		{
			if (
				!isset($removed[(string)$row['IF_FIELD_CODE']])
				&& !isset($removed[(string)$row['DO_FIELD_CODE']])
			)
			{
				continue;
			}

			$ids[] = (int)$row['ID'];
			if (count($ids) >= self::DELETE_PORTION)
			{
				FieldDependenceTable::deleteByFilter(['@ID' => $ids]);
				$ids = [];
			}
		}

		if ($ids !== [])
		{
			FieldDependenceTable::deleteByFilter(['@ID' => $ids]);
		}

		return new Result();
	}

	/**
	 * Rows of the form grouped by field code, each group in the order the form holds them.
	 *
	 * A group is a list rather than a single row because the storage has no unique key over the code:
	 * a form can hold one code more than once, and a map keyed by code would lose every row but the
	 * last, leaving it neither updated nor removed by a write that rewrites the whole collection.
	 *
	 * @return array<string, array<array{ID: int, ITEMS: array}>>
	 */
	private static function loadStoredRows(int $formId): array
	{
		$rows = FieldTable::query()
			->setSelect(['ID', 'CODE', 'ITEMS'])
			->where('FORM_ID', $formId)
			->setOrder(self::STORED_ROW_ORDER)
			->fetchAll()
		;

		$storedRows = [];
		foreach ($rows as $row)
		{
			$storedRows[(string)$row['CODE']][] = [
				'ID' => (int)$row['ID'],
				'ITEMS' => is_array($row['ITEMS'] ?? null) ? $row['ITEMS'] : [],
			];
		}

		return $storedRows;
	}

	/**
	 * Builds the columns the field specifies. An unspecified attribute is left out: on add it takes
	 * the creation default, on update it keeps the stored value.
	 *
	 * @return array<string, mixed>
	 */
	private static function createRow(Field $field, int $index): array
	{
		// The order of fields is their position in the input; the input carries no order of its own.
		$row = [
			'CODE' => (string)$field->name,
			'SORT' => ($index + 1) * self::SORT_STEP,
		];

		if ($field->type !== null)
		{
			$row['TYPE'] = $field->type;
		}
		if ($field->label !== null)
		{
			$row['CAPTION'] = $field->label;
		}
		if ($field->required !== null)
		{
			$row['REQUIRED'] = self::toFlag($field->required);
		}
		if ($field->multiple !== null)
		{
			$row['MULTIPLE'] = self::toFlag($field->multiple);
		}
		if ($field->placeholder !== null)
		{
			$row['PLACEHOLDER'] = $field->placeholder;
		}
		if ($field->valueType !== null)
		{
			$row['VALUE_TYPE'] = $field->valueType;
		}
		if ($field->value !== null)
		{
			$row['VALUE'] = $field->value;
		}

		return $row;
	}
}
