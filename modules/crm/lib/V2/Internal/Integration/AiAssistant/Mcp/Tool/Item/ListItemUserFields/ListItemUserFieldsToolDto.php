<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\ListItemUserFields;

use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ListItemUserFieldsToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),
		];
	}
}
