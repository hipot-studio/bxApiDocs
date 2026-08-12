<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AutomatedSolution\Update;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class UpdateToolDto extends AbstractToolDto
{
	public ?int $automatedSolutionId = null;
	public ?string $title = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'automatedSolutionId'),
			new IntegerField($this, 'automatedSolutionId', min: 0),
			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),
		];
	}
}
