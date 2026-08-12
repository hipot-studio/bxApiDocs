<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureAutomatization;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureAutomatizationToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;
	public ?bool $allowRobotsAndTriggers = null;
	public ?bool $allowBusinessProcesses = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),
		];
	}
}
