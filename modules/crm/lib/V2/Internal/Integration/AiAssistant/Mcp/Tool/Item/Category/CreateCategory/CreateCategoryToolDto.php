<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\CreateCategory;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\EntityTypeIdWithCategories;

final class CreateCategoryToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?string $title = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new EntityTypeIdWithCategories($this, 'entityTypeId'),

			new RequiredField($this, 'title'),
			new NotEmptyField($this, 'title'),
			new StringField($this, 'title'),
		];
	}
}
