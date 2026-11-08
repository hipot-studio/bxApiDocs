<?php

declare(strict_types=1);


namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Item\Category\Stage\SetStageList;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Validator\DefinedEntityTypeId;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Request\Stage\SetStageListItemDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Validator\CategoryField;

final class SetStageListToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $categoryId = null;
	public ?array $stages = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'stages' => new Caster\CollectionCaster(new Caster\ObjectCaster(SetStageListItemDto::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),
			new DefinedEntityTypeId($this, 'entityTypeId'),

			new CategoryField($this, 'entityTypeId', 'categoryId'),

			new RequiredField($this, 'stages'),
			new ObjectCollectionField($this, 'stages'),
		];

		if (array_key_exists('categoryId', $fields) && $fields['categoryId'] !== null)
		{
			$validators[] = new IntegerField($this, 'categoryId');
		}

		return $validators;
	}
}
