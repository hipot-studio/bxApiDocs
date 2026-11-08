<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\CreateItem;

use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\CategoryField;

final class CreateItemToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $categoryId = null;
	public ?string $title = null;

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new CategoryField($this, 'entityTypeId', 'categoryId'),

			new RequiredField($this, 'title'),
			new StringField($this, 'title'),
			new NotEmptyField($this, 'title'),
		];

		if (array_key_exists('categoryId', $fields) && $fields['categoryId'] !== null)
		{
			$validators[] = new IntegerField($this, 'categoryId');
		}

		return $validators;
	}
}
