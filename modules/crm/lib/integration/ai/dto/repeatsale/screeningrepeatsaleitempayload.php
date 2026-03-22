<?php

namespace Bitrix\Crm\Integration\AI\Dto\RepeatSale;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class ScreeningRepeatSaleItemPayload extends Dto
{
	public ?string $category = null;
	public ?bool $isRepeatSalePossible = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'isRepeatSalePossible'),
			new RequiredField($this, 'category'),
		];
	}
}
