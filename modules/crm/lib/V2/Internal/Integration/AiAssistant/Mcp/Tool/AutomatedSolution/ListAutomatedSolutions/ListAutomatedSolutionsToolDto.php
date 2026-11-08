<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\ListAutomatedSolutions;

use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListAutomatedSolutionsToolDto extends AbstractToolDto
{
	public ?string $query = null;

	protected function getValidators(array $fields): array
	{
		return [
			new StringField($this, 'query'),
			new NotEmptyField($this, 'query'),
		];
	}
}
