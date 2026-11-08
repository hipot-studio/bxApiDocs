<?php

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;

class ScoringCriteria extends Dto
{
	public string $criterion;
	public ?bool $status = null;
	public ?string $explanation;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'criterion'),
			new NotEmptyField($this, 'criterion'),
		];
	}
}
