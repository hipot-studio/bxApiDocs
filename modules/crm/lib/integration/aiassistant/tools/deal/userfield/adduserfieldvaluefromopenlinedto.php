<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Deal\UserField;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;

final class AddUserFieldValueFromOpenLineDto extends Dto
{
	public ?int $dealId = null;
	public ?string $fieldId = null;
	public ?string $fieldValue = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'dealId'),
			new IntegerField($this, 'dealId', min: 0),

			new StringField($this, 'fieldId'),
			new RequiredField($this, 'fieldId'),
			new NotEmptyField($this, 'fieldId'),

			new StringField($this, 'fieldValue'),
			new RequiredField($this, 'fieldValue'),
			new NotEmptyField($this, 'fieldValue'),
		];
	}
}
