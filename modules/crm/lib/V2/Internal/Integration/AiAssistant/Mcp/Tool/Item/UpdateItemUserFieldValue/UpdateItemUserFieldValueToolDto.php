<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\UpdateItemUserFieldValue;

use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class UpdateItemUserFieldValueToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $itemId = null;
	public ?string $fieldId = null;
	public ?string $fieldValue = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new RequiredField($this, 'itemId'),
			new IntegerField($this, 'itemId'),

			new RequiredField($this, 'fieldId'),
			new StringField($this, 'fieldId'),
			new NotEmptyField($this, 'fieldId'),

			new RequiredField($this, 'fieldValue'),
			new StringField($this, 'fieldValue'),
			new NotEmptyField($this, 'fieldValue'),
		];
	}
}
