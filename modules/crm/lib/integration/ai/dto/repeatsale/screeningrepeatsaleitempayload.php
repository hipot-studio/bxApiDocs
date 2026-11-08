<?php

namespace Bitrix\Crm\Integration\AI\Dto\RepeatSale;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class ScreeningRepeatSaleItemPayload extends Dto
{
	public ?string $category = null;
	public ?bool $isRepeatSalePossible = null;

	/**
	 * Optional on purpose: the same DTO is rebuilt from the JSON of already finished jobs
	 * (AbstractOperation::constructPayloadFromArray), where the key is absent, and a RequiredField
	 * would turn every accumulated result into a parse error. A missing key means no stop factor.
	 */
	public bool $stopFactor = false;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'isRepeatSalePossible'),
			new RequiredField($this, 'category'),
		];
	}
}
