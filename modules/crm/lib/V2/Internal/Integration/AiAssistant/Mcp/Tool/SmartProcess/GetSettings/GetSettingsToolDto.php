<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\GetSettings;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetSettingsToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			// min: 0 means "value > 0" — IntegerField uses strict comparison;
			// do NOT change to min: 1, that would reject the valid id 1.
			new IntegerField($this, 'smartProcessId', min: 0),
		];
	}
}
