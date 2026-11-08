<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Form;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\EntityFieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\EntityFieldItemDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\ScenarioCategoryDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary\ScenarioDto;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\EntityField;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\Scenario;
use Bitrix\Crm\V2\Internal\Entity\WebForm\Dictionary\ScenarioCategory;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds the DTOs of the three dictionaries an external form editor is built on. One direction only
 * - the methods behind these contracts read.
 *
 * The three dictionaries share one mapper because they are one answer of the portal, taken apart
 * into three methods for the sake of the caller: what belongs together stays in one place.
 */
final class FormDictionaryDtoMapper
{
	/**
	 * @param list<Scenario> $scenarios
	 * @return DtoCollection<ScenarioDto>
	 */
	public function getScenarioCollection(array $scenarios): DtoCollection
	{
		$collection = new DtoCollection(ScenarioDto::class);
		foreach ($scenarios as $scenario)
		{
			$collection->add(self::createScenarioDto($scenario));
		}

		return $collection;
	}

	/**
	 * @param list<ScenarioCategory> $categories
	 * @return DtoCollection<ScenarioCategoryDto>
	 */
	public function getScenarioCategoryCollection(array $categories): DtoCollection
	{
		$collection = new DtoCollection(ScenarioCategoryDto::class);
		foreach ($categories as $category)
		{
			$dto = new ScenarioCategoryDto();
			$dto->id = $category->id;
			$dto->title = $category->title;

			$collection->add($dto);
		}

		return $collection;
	}

	/**
	 * @param list<EntityField> $fields
	 * @return DtoCollection<EntityFieldDto>
	 */
	public function getEntityFieldCollection(array $fields): DtoCollection
	{
		$collection = new DtoCollection(EntityFieldDto::class);
		foreach ($fields as $field)
		{
			$collection->add(self::createEntityFieldDto($field));
		}

		return $collection;
	}

	private static function createScenarioDto(Scenario $scenario): ScenarioDto
	{
		$dto = new ScenarioDto();
		$dto->id = $scenario->id;
		$dto->title = $scenario->title;
		$dto->description = $scenario->description;
		$dto->categoryId = $scenario->categoryId;
		$dto->iconUrl = $scenario->iconUrl;
		$dto->sections = $scenario->sections;
		$dto->expertSections = $scenario->expertSections;
		$dto->defaultSection = $scenario->defaultSection;
		$dto->openable = $scenario->openable;
		$dto->soon = $scenario->soon;
		$dto->actions = $scenario->actions;

		return $dto;
	}

	private static function createEntityFieldDto(EntityField $field): EntityFieldDto
	{
		$items = new DtoCollection(EntityFieldItemDto::class);
		foreach ($field->items as $item)
		{
			$itemDto = new EntityFieldItemDto();
			$itemDto->id = $item['id'];
			$itemDto->label = $item['label'];

			$items->add($itemDto);
		}

		$dto = new EntityFieldDto();
		$dto->name = $field->name;
		$dto->label = $field->label;
		$dto->type = $field->type;
		$dto->required = $field->required;
		$dto->multiple = $field->multiple;
		$dto->entityName = $field->entityName;
		$dto->entityLabel = $field->entityLabel;
		$dto->items = $items;

		return $dto;
	}
}
