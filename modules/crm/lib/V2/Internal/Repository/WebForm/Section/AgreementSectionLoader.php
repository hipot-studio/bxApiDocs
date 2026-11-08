<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\WebForm\Section;

use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\AgreementItem;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Section\Agreements;
use Bitrix\Crm\V2\Internal\Entity\WebForm\WebForm;
use Bitrix\Crm\WebForm\Internals\AgreementTable;

/**
 * @internal
 */
final class AgreementSectionLoader extends WebFormSectionLoader
{
	private const ITEMS_PATH = Agreements::FIELD_ITEMS;

	private const COLUMN_BY_PATH = [
		self::ITEMS_PATH . '.' . AgreementItem::FIELD_AGREEMENT_ID => 'AGREEMENT_ID',
		self::ITEMS_PATH . '.' . AgreementItem::FIELD_CHECKED => 'CHECKED',
		self::ITEMS_PATH . '.' . AgreementItem::FIELD_REQUIRED => 'REQUIRED',
	];

	public function getSectionName(): string
	{
		return WebForm::SECTION_AGREEMENTS;
	}

	/**
	 * @param string[] $selectedFields
	 */
	protected function createEmptySection(array $selectedFields): mixed
	{
		if ($selectedFields === [])
		{
			return [];
		}

		return new Agreements(
			enabled: null,
			items: self::hasItemsSelected($selectedFields) ? [] : null,
		);
	}

	/**
	 * @param int[] $formIds
	 * @param string[] $selectedFields
	 * @return array<int, Agreements>
	 */
	protected function loadSelected(array $formIds, array $selectedFields): array
	{
		if (!self::hasItemsSelected($selectedFields))
		{
			return [];
		}

		$selectedFieldMap = array_fill_keys($selectedFields, true);
		$columns = ['FORM_ID', 'ID'];
		foreach (self::COLUMN_BY_PATH as $path => $column)
		{
			if (self::isSelected($selectedFieldMap, $path))
			{
				$columns[] = $column;
			}
		}

		$query =
			AgreementTable::query()
				->setSelect($columns)
				->whereIn('FORM_ID', $formIds)
				->setOrder([
					'FORM_ID' => 'ASC',
					'ID' => 'ASC',
				])
		;

		$itemsByFormId = [];
		$result = $query->exec();
		while ($row = $result->fetch())
		{
			$formId = (int)$row['FORM_ID'];
			$itemsByFormId[$formId][] = new AgreementItem(
				agreementId: self::integer(
					$row,
					$selectedFieldMap,
					self::ITEMS_PATH . '.' . AgreementItem::FIELD_AGREEMENT_ID,
				),
				checked: self::boolean(
					$row,
					$selectedFieldMap,
					self::ITEMS_PATH . '.' . AgreementItem::FIELD_CHECKED,
				),
				required: self::boolean(
					$row,
					$selectedFieldMap,
					self::ITEMS_PATH . '.' . AgreementItem::FIELD_REQUIRED,
				),
			);
		}

		$result = [];
		foreach ($itemsByFormId as $formId => $items)
		{
			$result[$formId] = new Agreements(enabled: null, items: $items);
		}

		return $result;
	}

	/**
	 * @param string[] $selectedFields
	 */
	private static function hasItemsSelected(array $selectedFields): bool
	{
		foreach ($selectedFields as $selectedField)
		{
			if (
				$selectedField === self::ITEMS_PATH
				|| str_starts_with($selectedField, self::ITEMS_PATH . '.')
			)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, true> $selectedFields
	 */
	private static function isSelected(array $selectedFields, string $path): bool
	{
		return isset($selectedFields[$path]) || isset($selectedFields[self::ITEMS_PATH]);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function integer(array $row, array $selectedFields, string $path): ?int
	{
		if (!self::isSelected($selectedFields, $path))
		{
			return null;
		}

		return (int)($row[self::COLUMN_BY_PATH[$path]] ?? 0);
	}

	/**
	 * @param array<string, mixed> $row
	 * @param array<string, true> $selectedFields
	 */
	private static function boolean(array $row, array $selectedFields, string $path): ?bool
	{
		if (!self::isSelected($selectedFields, $path))
		{
			return null;
		}

		$value = $row[self::COLUMN_BY_PATH[$path]] ?? null;

		return $value === true || $value === 'Y' || $value === 1 || $value === '1';
	}
}
