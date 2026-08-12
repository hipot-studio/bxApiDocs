<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureSystemFields;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureSystemFieldsToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;
	public ?bool $allowClient = null;
	public ?bool $allowBeginEndDates = null;
	public ?bool $allowMyCompany = null;
	public ?bool $allowSource = null;
	public ?bool $allowObservers = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),
		];
	}
}
