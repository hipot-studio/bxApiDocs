<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\CreateAutomatedSolution;

use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\Dto\Validator\StringLengthField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class CreateAutomatedSolutionToolDto extends AbstractToolDto
{
	public const MAX_TITLE_LENGTH = 255;

	public ?string $title = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),
			new StringLengthField($this, 'title', max: self::MAX_TITLE_LENGTH),
		];
	}
}
