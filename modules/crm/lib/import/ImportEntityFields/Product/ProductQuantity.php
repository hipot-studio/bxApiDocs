<?php

namespace Bitrix\Crm\Import\ImportEntityFields\Product;

use Bitrix\Crm\Import\Contract\ImportEntityFieldInterface;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Main\Localization\Loc;

final class ProductQuantity implements ImportEntityFieldInterface
{
	public function getId(): string
	{
		return 'PRODUCT_QUANTITY';
	}

	public function getCaption(): string
	{
		return Loc::getMessage('CRM_ITEM_IMPORT_FIELD_PRODUCT_QUANTITY');
	}

	public function isRequired(): bool
	{
		return false;
	}

	public function isReadonly(): bool
	{
		return false;
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		/** @see ProductId::process() */
		return FieldProcessResult::success();
	}
}
