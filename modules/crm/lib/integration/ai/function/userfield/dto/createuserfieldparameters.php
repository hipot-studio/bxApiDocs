<?php

namespace Bitrix\Crm\Integration\AI\Function\UserField\Dto;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\DefinedCategoryIdentifier;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

class CreateUserFieldParameters extends Dto
{
	public int $entityTypeId;
	public ?int $categoryId = null;
	public string $label;

	protected function getValidators(array $fields): array
	{
		return [
			new DefinedCategoryIdentifier($this, 'entityTypeId', 'categoryId'),
			new RequiredField($this, 'label'),
			new NotEmptyField($this, 'label'),
		];
	}
}
