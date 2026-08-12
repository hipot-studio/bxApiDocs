<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Dto\Validator\DefinedCategoryIdentifier;
use Bitrix\Crm\Dto\Validator\EntityType\IsPossibleDynamicType;
use Bitrix\Crm\Dto\Validator\EnumField;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\Logic;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\ObjectCollectionField;
use CCrmOwnerType;

final class UpdateListParameters extends Dto
{
	public int $entityTypeId;
	public int $categoryId;

	/** @var UpdateListStage[] */
	public array $stages;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName) {
			'stages' => new Caster\CollectionCaster(new Caster\ObjectCaster(UpdateListStage::class)),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		return [
			Logic::or($this, [
				new EnumField($this, 'entityTypeId', [
					CCrmOwnerType::Deal,
				]),
				new IsPossibleDynamicType($this, 'entityTypeId'),
			]),

			new IntegerField($this, 'categoryId', min: 0),
			new DefinedCategoryIdentifier($this, 'entityTypeId', 'categoryId'),

			new ObjectCollectionField($this, 'stages'),
			new NotEmptyField($this, 'stages'),
		];
	}
}
