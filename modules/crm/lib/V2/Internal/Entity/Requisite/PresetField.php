<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Entity\Requisite;

/**
 * One entry of the composition of a preset: something the preset shows, under the title and in the order
 * the portal configured for it.
 *
 * An entry names one of three different things, and which one decides what a caller may do with it:
 *
 * - `field` - a field of the requisite contract. The only kind a write may address, and the only kind
 *   {@see Preset::hasField()} answers for.
 * - `userField` - a user field of the portal. The group publishes no name of its own for one: it is in
 *   the composition because the interface shows it, and it is named apart so that a client can tell it
 *   is not from the vocabulary of the group.
 * - `isAddress` - the marker that says the preset shows the address of the requisite. Neither a field nor
 *   a column: the address of a requisite is a record of its own, and this marker is the only reason the
 *   composition mentions it at all.
 *
 * At most one of the three is set. None of them set is an entry the area does not recognize - a name left
 * in the setting by a field that no longer exists - and it is carried rather than dropped: what a
 * composition may leave out belongs to the read contract above, not to the boundary that assembles it.
 *
 * Read-only by construction - {@see \Bitrix\Crm\V2\Internal\Repository\Requisite\PresetRepository} is the
 * only place that puts one together, because the setting it comes from is written in storage names and
 * those do not leave the repository.
 *
 * @internal
 */
final readonly class PresetField
{
	/**
	 * @param int $id identifier of the entry inside the preset, unique within it and not across presets.
	 * @param string|null $field field name of the requisite contract, or `null` when the entry names no
	 *        field of it.
	 * @param string|null $userField name of a user field of the portal, or `null` when the entry is not
	 *        one. A non-empty value is the mark: the client is told what to call the field and, by the
	 *        same value, that the group does not answer for it.
	 * @param bool $isAddress whether the entry is the marker of the address rather than a field.
	 * @param string $title the title the portal gave the field in this preset; empty when it kept the
	 *        default title of the field, which depends on the country of the portal and is not stored
	 *        here.
	 * @param bool $inShortList whether the field belongs to the short form the interface shows first.
	 * @param int $sort the order of the entry within the preset.
	 */
	public function __construct(
		public int $id,
		public ?string $field,
		public ?string $userField,
		public bool $isAddress,
		public string $title,
		public bool $inShortList,
		public int $sort,
	)
	{
	}
}
