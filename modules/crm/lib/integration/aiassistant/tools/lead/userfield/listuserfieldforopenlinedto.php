<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Lead\UserField;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class ListUserFieldForOpenLineDto extends Dto
{
	public ?int $leadId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'leadId'),
			new IntegerField($this, 'leadId', min: 0),
		];
	}
}
