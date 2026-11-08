<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\Sync\Card;

use Bitrix\Disk\Infrastructure\Rest\V3\Dto\Sync\PlaceDto;

/**
 * The places of one page, addressed by the real object.
 *
 * Keyed by the real object and not by the row that was read: a link and the object behind it are one
 * card, and the same object found through several links must not become several entries. What the
 * rows keep to themselves - their own name, their own mark of deletion - lives inside the places,
 * not in the key.
 *
 * An object with no places is held as an object with an empty list rather than as a missing key: the
 * absence of places is an answer about where the object lies, never an answer about whether it is
 * visible.
 */
final class PlaceSet
{
	/**
	 * @param array<int, PlaceDto[]> $placesByRealObjectId
	 */
	private function __construct(private readonly array $placesByRealObjectId)
	{
	}

	/**
	 * @param array<int, PlaceDto[]> $placesByRealObjectId
	 */
	public static function create(array $placesByRealObjectId): self
	{
		return new self($placesByRealObjectId);
	}

	public static function createEmpty(): self
	{
		return new self([]);
	}

	/**
	 * @return PlaceDto[]
	 */
	public function getPlacesOf(int $realObjectId): array
	{
		return $this->placesByRealObjectId[$realObjectId] ?? [];
	}

	/**
	 * @return int[] the objects the set was collected for, in the order they were asked about
	 */
	public function getObjectIds(): array
	{
		return array_keys($this->placesByRealObjectId);
	}
}
