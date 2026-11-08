<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\DeleteCategory;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\CategoryField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\EntityTypeIdWithCategories;

final class DeleteCategoryToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $categoryId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new EntityTypeIdWithCategories($this, 'entityTypeId'),

			new CategoryField($this, 'entityTypeId', 'categoryId'),

			new RequiredField($this, 'categoryId'),
			new IntegerField($this, 'categoryId'),
		];
	}
}
