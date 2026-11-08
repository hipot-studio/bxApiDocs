<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Form\Dictionary;

use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\Title;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * A CRM field a form may be built of, named together with the entity owning it.
 *
 * The dictionary is flat on purpose: the entity of a field travels with the field itself, and a tree
 * over it is a view the client is free to build. It is answered whole unless the caller narrows it
 * to the entities it is interested in, so the entity is the one property that filters and nothing
 * here sorts.
 */
#[Title('CRM field available for forms')]
final class EntityFieldDto extends Dto
{
	/** The code of the field; what the field of a form refers to. */
	public string $name;

	/** The name of the field as the portal keeps it; nothing is escaped here. */
	public string $label;

	/** The kind of the field: what a form draws for it and what a submission carries. */
	public string $type;

	public bool $required;

	public bool $multiple;

	/** The entity owning the field, by its code. */
	#[Filterable]
	public string $entityName;

	/** The name of that entity as the portal keeps it; nothing is escaped here. */
	public string $entityLabel;

	/**
	 * The values of a field that enumerates them, empty for every other field.
	 *
	 * @var DtoCollection<EntityFieldItemDto>
	 */
	#[ElementType(EntityFieldItemDto::class)]
	public DtoCollection $items;
}
