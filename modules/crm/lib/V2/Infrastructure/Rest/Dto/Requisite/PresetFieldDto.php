<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * One entry of the composition of a preset, as the two preset methods of the group answer it inside the
 * preset it belongs to.
 *
 * WHAT THE NAME IS DEPENDS ON THE KIND. An entry names a field of the requisite contract, a user field of
 * the portal or nothing at all, and {@see PresetFieldKind} says which. So a client reads `name` against
 * `kind` rather than against a guess: only the name of a field of the requisite contract can be sent back
 * in a write of a requisite, the name of a user field belongs to the portal, and the marker of the address
 * names nothing.
 *
 * Names are the external ones of the group ({@see AbstractOwnerRequisiteDto}), not the columns the preset
 * is stored by: a client that reads a composition can address the same fields it reads in a requisite.
 *
 * READ-ONLY, AND NOT BY OMISSION. Not one field carries {@see \Bitrix\Rest\V3\Attribute\Editable}:
 * managing the presets of a portal is not published by this group, so a client sending a composition is
 * refused by name rather than having the value quietly dropped. Nothing is filterable or sortable either -
 * an entry is answered as part of a preset and is never the thing a read is addressed to.
 */
final class PresetFieldDto extends Dto
{
	/**
	 * Empty when the entry is the marker of the address: there is no field to name.
	 */
	public string $name;

	public PresetFieldKind $kind;

	/**
	 * The title the portal gave the field in this preset; empty when the portal kept the default title,
	 * which depends on the country of the portal and is not part of the preset.
	 */
	public string $title;

	/** Whether the field belongs to the short form the interface shows first. */
	public bool $inShortList;

	/** The order of the entry within the preset, as the interface shows it. */
	public int $sort;
}
