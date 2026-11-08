<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

/**
 * One entry of the composition of a preset: something the preset shows, under the title and in the
 * order the portal configured for it.
 *
 * An entry names one of three different things, and which one decides what a consumer may do with it.
 * They are told apart by two marks rather than by one kind, because the marks answer different
 * questions - whether the entry is a field of the group at all, and whether it is the address:
 *
 * - neither mark set - a field of the requisite contract, named as {@see Requisite} names it. The only
 *   kind a write may address;
 * - `isUserField` - a user field of the portal, named as the portal names it. The group publishes no
 *   name of its own for one and answers for none: it is in the composition because the interface shows
 *   it, and the mark is what says a client should not look for it among the fields of a requisite;
 * - `isAddress` - the marker that says the preset shows the address of the requisite. Neither a field
 *   nor a user field: the address of a requisite is a record of its own
 *   ({@see RequisiteAddress}), and this marker is the only reason the composition mentions it at all.
 *   Its name is empty, because there is no field to name.
 *
 * An entry that names something the group no longer knows is left out of the composition by the read
 * that assembles it, so every entry published here names one of the three.
 */
final readonly class PresetField
{
	/**
	 * @param string $name the name of the field as the contract of the group names it, the name of the
	 *        user field when `$isUserField`, and empty when `$isAddress`.
	 * @param string $title the title the portal gave the field in this preset; empty when the portal
	 *        kept the default title, which depends on the country of the portal and is not part of the
	 *        preset.
	 * @param bool $inShortList whether the field belongs to the short form the interface shows first.
	 * @param int $sort the order of the entry within the preset.
	 */
	public function __construct(
		private string $name,
		private bool $isAddress,
		private bool $isUserField,
		private string $title,
		private bool $inShortList,
		private int $sort,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function isAddress(): bool
	{
		return $this->isAddress;
	}

	public function isUserField(): bool
	{
		return $this->isUserField;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function isInShortList(): bool
	{
		return $this->inShortList;
	}

	public function getSort(): int
	{
		return $this->sort;
	}
}
