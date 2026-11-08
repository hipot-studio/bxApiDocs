<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Rest\V3\Attribute\Sortable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\DtoField;

/**
 * The external contract of a preset of a requisite, as the two reading methods of the group answer it.
 *
 * A preset is the choice of a jurisdiction ({@see \Bitrix\Crm\V2\Public\Entity\Requisite\Preset}): it
 * decides which fields of a requisite mean anything, what the interface calls them and in what order it
 * shows them. That is why the group publishes it at all - it is where a client learns the presets it may
 * name in `presetId` of a requisite, and what naming one will get it.
 *
 * READ-ONLY, AND NOT BY OMISSION. Not one field carries {@see \Bitrix\Rest\V3\Attribute\Editable}:
 * managing the presets of a portal is not part of this group, so a client sending a preset is refused by
 * name rather than having the value quietly dropped. Nothing is filterable: a portal has few presets and
 * the list answers them all. Only `id` and `sort` are sortable - `sort` is the order the interface shows,
 * and `id` is what the order falls back to.
 *
 * `isDefault` IS WORKED OUT ON A READ AND IS KEPT NOWHERE. Being the default is not a property of a
 * preset - the same preset is the default one for companies and an ordinary one for contacts - so there
 * is no column of it to look for. Every route of the group carries the type of its owner, so the question
 * is always asked and the answer is always a boolean; the domain answers `null` only to a read that named
 * no owner type, which is not a read of this group.
 */
#[Title('CRM details template')]
final class PresetDto extends Dto
{
	#[Sortable]
	public int $id;

	/** The name of the preset as the portal gave it, not a name of the product. */
	public string $name;

	/** True for at most one preset of an answer: the default one for the owner type of the route. */
	public bool $isDefault;

	#[Sortable]
	public int $sort;

	/**
	 * The composition of the preset, in the order the interface shows it: a collection of
	 * {@see PresetFieldDto}, of which every entry answers for one of the three kinds a preset shows.
	 *
	 * Declared here rather than as a property because {@see Dto} keeps its own `$fields` - the collection
	 * of the fields of the contract - and a property of that name would shadow it. The name is the one
	 * the contract publishes, so the composition is declared the other way the framework offers instead
	 * of being renamed: a client of a preset asks for `fields` and gets them, and the shape below is the
	 * same one a nested collection declared as a property has.
	 *
	 * @return DtoField[]
	 */
	protected function getExtraFields(): array
	{
		return [
			new DtoField(
				propertyName: 'fields',
				propertyType: DtoCollection::class,
				type: DtoField::DTO_FIELD_TYPE_DYNAMIC_FIELD,
				nullable: true,
				elementType: PresetFieldDto::class,
			),
		];
	}
}
