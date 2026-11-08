<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * Somebody a card names: an identifier and a name to show, and nothing besides.
 *
 * The card names people the client never asked about - whoever created a file, whoever changed it
 * last, whoever owns the storage it lies in - and what may be told about them is the whole of this
 * class. A photo, a position, an address are not withheld by the collector that fills this in; they
 * have no field to arrive in, which is the only form of that limit that survives later edits.
 * Widening it is a decision of the owner of the feature, not of the code that builds a card.
 *
 * The name is a convenience and the identifier is the answer: a party the portal no longer knows
 * still gets an identifier, with an empty name beside it.
 */
final class EntityRefDto extends Dto
{
	public int $id;

	public string $name;
}
