<?php

namespace Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\Stage;

use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\ImportEntityFields\FactoryBasedField\AbstractFactoryBasedField;
use Bitrix\Crm\Import\ImportEntityFields\Trait\CanConfigureDefaultCategoryIdTrait;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\Import\Strategy\ValueMapper\StageIdValueMapper;
use Bitrix\Crm\Item;

final class StageId extends AbstractFactoryBasedField
{
	use CanConfigureDefaultCategoryIdTrait;

	public function getId(): string
	{
		return Item::FIELD_NAME_STAGE_ID;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		return (new StageIdValueMapper($this->getId(), $this->factory))
			->configureDefaultCategoryId($this->defaultCategoryId)
			->process($importItemFields, $fieldBindings, $row)
		;
	}
}
