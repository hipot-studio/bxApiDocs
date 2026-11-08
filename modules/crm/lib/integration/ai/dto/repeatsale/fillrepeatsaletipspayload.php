<?php

namespace Bitrix\Crm\Integration\AI\Dto\RepeatSale;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Main\Result;

final class FillRepeatSaleTipsPayload extends Dto
{
	public ?CustomerInfo $customerInfo = null;
	public ?ActionPlan $actionPlan = null;

	/**
	 * Optional and not nullable on purpose: the same DTO is rebuilt from the JSON of already finished
	 * jobs (AbstractOperation::constructPayloadFromArray), where the key is absent, and Dto::toArray()
	 * drops nulls.
	 */
	public bool $noRecommendation = false;

	protected function getValidators(array $fields): array
	{
		return [
			new Validator\ObjectField($this, 'customerInfo'),
			new Validator\ObjectField($this, 'actionPlan'),
			new class($this) extends Validator {
				public function validate(array $fields): Result
				{
					$result = new Result();

					if (
						empty($fields['customerInfo'])
						&& empty($fields['actionPlan'])
						&& empty($fields['noRecommendation'])
					)
					{
						$result->addError(ErrorCode::getInvalidPayloadError());
					}

					return $result;
				}
			},
		];
	}
}
