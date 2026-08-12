<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureExternalVisibility;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureExternalVisibilityToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;
	public ?bool $allowInCrmField = null;
	public ?bool $allowInTasks = null;
	public ?bool $allowInCalendar = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),
		];
	}
}
