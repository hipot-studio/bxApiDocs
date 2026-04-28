<?php

namespace Bitrix\Crm\Import\Strategy\ValueMapper;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\CategoryId;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureDefaultCategoryIdTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Service\Factory;

final class StageIdValueMapper
{
	use CanConfigureDefaultCategoryIdTrait;

	private readonly CategoryId $categoryIdField;

	public function __construct(
		private readonly string $fieldId,
		private readonly Factory $factory,
	)
	{
		$this->categoryIdField = new CategoryId($this->factory->getEntityTypeId());
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$stageIdColumnIndex = $fieldBindings->getColumnIndexByFieldId($this->fieldId);
		if ($stageIdColumnIndex === null)
		{
			return FieldProcessResult::skip();
		}

		$possibleStageId = $row[$stageIdColumnIndex] ?? null;
		if (empty($possibleStageId))
		{
			return FieldProcessResult::skip();
		}

		$categoryId = $importItemFields[$this->categoryIdField->getId()] ?? $this->defaultCategoryId;
		if (!is_int($categoryId) && $this->factory->isCategoriesSupported())
		{
			return FieldProcessResult::skip();
		}

		$categoryId = is_numeric($categoryId) ? (int)$categoryId : null;

		$stageCollection = $this->factory->getStages($categoryId);
		foreach ($stageCollection->getAll() as $stage)
		{
			if (
				$stage->getStatusId() === $possibleStageId
				|| $stage->getName() === $possibleStageId
			)
			{
				$importItemFields[$this->fieldId] = $stage->getStatusId();

				return FieldProcessResult::success();
			}
		}

		return FieldProcessResult::skip();
	}
}
