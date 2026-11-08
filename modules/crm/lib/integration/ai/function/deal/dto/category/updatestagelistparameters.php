<?php

namespace Bitrix\Crm\Integration\AI\Function\Deal\Dto\Category;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\DefinedCategory;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use Bitrix\Crm\Dto\Validator\RequiredField;

final class UpdateStageListParameters extends Dto
{
	public int $categoryId;

	/** @var Stage[] */
	public array $stages;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'stages' => new Caster\CollectionCaster(new Caster\ObjectCaster(Stage::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'categoryId'),
			new IntegerField($this, 'categoryId', min: -1),
			new DefinedCategory($this, \CCrmOwnerType::Deal, 'categoryId'),

			new RequiredField($this, 'stages'),
			new NotEmptyField($this, 'stages'),
			new ObjectCollectionField($this, 'stages'),
		];
	}
}
