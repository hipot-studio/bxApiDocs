<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AiAssistant\Tools\Deal\UserField;

use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class ListUserFieldForOpenLineDto extends Dto
{
	public ?int $dealId = null;

	public function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'dealId'),
			new IntegerField($this, 'dealId', min: 0),
		];
	}
}
