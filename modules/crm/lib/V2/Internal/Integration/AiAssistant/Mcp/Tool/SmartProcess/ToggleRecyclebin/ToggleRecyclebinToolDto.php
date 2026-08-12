<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ToggleRecyclebin;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ToggleRecyclebinToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;
	public ?bool $allowRecyclebin = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),
			new RequiredField($this, 'allowRecyclebin'),
		];
	}
}
