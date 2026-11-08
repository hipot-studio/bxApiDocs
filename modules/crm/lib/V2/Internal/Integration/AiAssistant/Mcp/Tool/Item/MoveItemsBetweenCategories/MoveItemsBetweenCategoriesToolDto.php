<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\MoveItemsBetweenCategories;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\CategoryField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\EntityTypeIdWithCategories;

final class MoveItemsBetweenCategoriesToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $sourceCategoryId = null;
	public ?int $targetCategoryId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new EntityTypeIdWithCategories($this, 'entityTypeId'),

			new RequiredField($this, 'sourceCategoryId'),
			new IntegerField($this, 'sourceCategoryId'),
			new CategoryField($this, 'entityTypeId', 'sourceCategoryId'),

			new RequiredField($this, 'targetCategoryId'),
			new IntegerField($this, 'targetCategoryId'),
			new CategoryField($this, 'entityTypeId', 'targetCategoryId'),
		];
	}
}
