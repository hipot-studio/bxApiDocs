<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\SmartProcess\ConfigureAutomatedSolution;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ConfigureAutomatedSolutionToolDto extends AbstractToolDto
{
	public ?int $smartProcessId = null;
	public ?int $automatedSolutionId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'smartProcessId'),
			new IntegerField($this, 'smartProcessId', min: 0),
			new RequiredField($this, 'automatedSolutionId'),
			// automatedSolutionId >= 0: 0 means unbind; strict > so min: -1
			new IntegerField($this, 'automatedSolutionId', min: -1),
		];
	}
}
