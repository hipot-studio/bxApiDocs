<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetFieldDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\PresetFieldKind;
use Bitrix\Crm\V2\Public\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Public\Entity\Requisite\PresetField;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Builds {@see PresetDto} out of the public {@see Preset}. One direction only - the two preset methods of
 * the group read, and managing the presets of a portal is not published, so there is no write path to map
 * back into.
 *
 * NO MAPPER OF THE REQUEST BESIDE THIS ONE, AND NOT BY OMISSION. A preset is addressed by its identifier
 * and a list of them is ordered by `id` or `sort`; the public read takes that ordering in the names the
 * contract publishes ({@see \Bitrix\Crm\V2\Public\Provider\Requisite\PresetProvider::getList()}), so
 * there is no vocabulary to cross and nothing for a mapper to translate. The owner type of the route is
 * the trusted invariant the controller carries, and a client never sends one.
 *
 * A preset is answered whole - the requests of the two methods carry no selection - so every field below
 * is filled for every preset, the composition included. That is what a client comes to these methods for:
 * the composition is read along with the preset rather than by a read of its own.
 */
final class PresetDtoMapper
{
	public function getDtoByPreset(Preset $preset): PresetDto
	{
		$dto = new PresetDto();
		$dto->id = $preset->getId();
		$dto->name = $preset->getName();

		// The domain answers `null` only to a read that named no owner type, and both reads of this group
		// name one, so the question was always asked. The cast keeps the contract of the route free of a
		// third state a client would have no way to read.
		$dto->isDefault = (bool)$preset->getIsDefault();
		$dto->sort = $preset->getSort();

		$fields = new DtoCollection(PresetFieldDto::class);
		foreach ($preset->getFields() as $field)
		{
			$fields->add(self::createDtoByPresetField($field));
		}

		// Written through the field rather than through a property: `fields` is the name the contract
		// publishes the composition under, and {@see \Bitrix\Rest\V3\Dto\Dto} keeps a property of that
		// name for the fields of the contract itself ({@see PresetDto::getExtraFields()}).
		$dto->__set('fields', $fields);

		return $dto;
	}

	/**
	 * @param Preset[] $presets
	 */
	public function getDtoCollectionByPresets(array $presets): DtoCollection
	{
		$collection = new DtoCollection(PresetDto::class);
		foreach ($presets as $preset)
		{
			$collection->add($this->getDtoByPreset($preset));
		}

		return $collection;
	}

	/**
	 * The two marks the domain tells the three kinds apart by become the one value the contract answers
	 * with ({@see PresetFieldKind}), so a client reads a kind instead of working out which pair of
	 * booleans is possible.
	 */
	private static function createDtoByPresetField(PresetField $field): PresetFieldDto
	{
		$dto = new PresetFieldDto();
		$dto->name = $field->getName();
		$dto->kind = match (true)
		{
			$field->isAddress() => PresetFieldKind::Address,
			$field->isUserField() => PresetFieldKind::UserField,
			default => PresetFieldKind::RequisiteField,
		};
		$dto->title = $field->getTitle();
		$dto->inShortList = $field->isInShortList();
		$dto->sort = $field->getSort();

		return $dto;
	}
}
