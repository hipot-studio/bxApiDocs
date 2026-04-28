<?php

namespace Bitrix\Crm\RepeatSale\Sandbox\Grid\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;
use CCrmOwnerType;

final class EntityTypeFieldAssembler extends FieldAssembler
{
	protected function prepareColumn($value): string
	{
		$entityTypeId = (int)$value;
		if (!CCrmOwnerType::isCorrectEntityTypeId($entityTypeId))
		{
			return '';
		}

		return CCrmOwnerType::getDescription($entityTypeId);
	}
}
