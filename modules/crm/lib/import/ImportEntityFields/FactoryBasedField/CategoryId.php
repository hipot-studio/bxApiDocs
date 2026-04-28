<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField;

use Bitrix\Crm\Category\Entity\Category;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureDefaultCategoryIdTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Item;

final class CategoryId extends AbstractFactoryBasedField
{
	use CanConfigureDefaultCategoryIdTrait;

	public function getId(): string
	{
		return Item::FIELD_NAME_CATEGORY_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$category = $this->getCategoryFromRow($fieldBindings, $row);

		$isUseDefaultValue = $category === null && $this->defaultCategoryId !== null;
		if ($isUseDefaultValue)
		{
			$importItemFields[$this->getId()] = $this->defaultCategoryId;

			return FieldProcessResult::success();
		}

		if ($category === null)
		{
			return FieldProcessResult::skip();
		}

		$importItemFields[$this->getId()] = $category->getId();

		return FieldProcessResult::success();
	}

	private function getCategoryFromRow(FieldBindings $fieldBindings, array $row): ?Category
	{
		$categoryIdColumnIndex = $fieldBindings->getColumnIndexByFieldId($this->getId());
		if ($categoryIdColumnIndex === null)
		{
			return null;
		}

		$possibleCategoryId = $row[$categoryIdColumnIndex] ?? null;
		if (empty($possibleCategoryId))
		{
			return null;
		}

		return $this->factory->getCategoryByFilter(
			static fn (Category $category) => $category->getName() === $possibleCategoryId,
		);
	}
}
