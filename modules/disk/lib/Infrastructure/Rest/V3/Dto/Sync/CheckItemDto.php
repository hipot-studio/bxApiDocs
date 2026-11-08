<?php

declare(strict_types=1);

namespace Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync;

use Bitrix\Rest\V3\Dto\Dto;

/**
 * What an addressed check answers about one object: it was asked about, and the reader either sees it
 * right now or does not.
 *
 * An object the reader does not see carries no card - not an empty one and not a partial one. That is
 * the whole invariant of the check on the wire: an object hidden from the reader and an object that
 * was never there are described by these same two fields and by nothing else, so no answer of this
 * method can be read as an oracle of existence or walked to enumerate the identifiers of a portal.
 * There is no reason field for the same reason.
 *
 * A visible object may still arrive without a card: it was deleted between the verdict that named it
 * and the assembly that describes it. The client reads it back rather than deleting it - "you see it"
 * has already been said, and the journal is what says the opposite when it becomes true.
 *
 * Property order is the order of the published answer.
 */
final class CheckItemDto extends Dto
{
	/** Always the real object, as the cards of the contour name it. */
	public readonly int $objectId;

	public readonly bool $visible;

	/** Only with a visible object, and only while the portal can still describe it. */
	public readonly ObjectCardDto $card;

	public static function visible(int $objectId, ?ObjectCardDto $card = null): self
	{
		$item = self::of($objectId, true);
		if ($card !== null)
		{
			$item->card = $card;
		}

		return $item;
	}

	public static function invisible(int $objectId): self
	{
		return self::of($objectId, false);
	}

	private static function of(int $objectId, bool $visible): self
	{
		$item = new self();
		$item->objectId = $objectId;
		$item->visible = $visible;

		return $item;
	}
}
