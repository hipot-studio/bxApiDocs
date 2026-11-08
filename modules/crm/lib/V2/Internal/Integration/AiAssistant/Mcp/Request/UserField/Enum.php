<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\UserField;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;

final class Enum extends Dto
{
	public ?string $value = null;
	public ?bool $default = null;
	public ?int $sort = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'value'),
			new StringField($this, 'value'),
			new NotEmptyField($this, 'value'),

			new RequiredField($this, 'default'),
			new EnumField($this, 'default', [true, false]),

			new RequiredField($this, 'sort'),
			new IntegerField($this, 'sort'),
		];
	}
}
