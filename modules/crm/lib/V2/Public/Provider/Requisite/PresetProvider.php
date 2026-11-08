<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Preset as InternalPreset;
use Bitrix\Crm\V2\Internal\Entity\Requisite\PresetField as InternalPresetField;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Crm\V2\Internal\Service\Requisite\OwnerResolver;
use Bitrix\Crm\V2\Internal\Service\Requisite\PresetResolver;
use Bitrix\Crm\V2\Public\Entity\Requisite\Preset;
use Bitrix\Crm\V2\Public\Entity\Requisite\PresetField;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Crm\V2\Public\Provider\Requisite\Exception\NotFoundException;
use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\SystemException;

/**
 * Read-side public API for the presets of a requisite - the jurisdictions a portal offers, and what each
 * of them decides a requisite means by its fields.
 *
 * Read-only, and not by omission: managing the presets of a portal is not part of this group, and neither
 * is the portal-wide setting of the default preset - the card of a requisite writes that one while
 * reading it, and this provider deliberately does not.
 *
 * ### The owner type is an argument of every read
 *
 * The composition of presets is the same one for a contact and for a company; what differs is which of
 * them is the default one, and that is a property of the owner type rather than of the preset. So both
 * reads are given an owner type, and the two named alike are not duplicates of each other.
 *
 * ### Two refusals, and they are not the same one
 *
 * A user who may not read the entities of the owner type may not read its presets either, and that is an
 * {@see AccessDeniedException}: it is a refusal of the action rather than an answer about an object, and
 * nothing about it says which presets a portal keeps. Legacy asks for access to contacts **and**
 * companies at once for the same question; this asks for the owner type of the caller alone, which is a
 * deliberate difference of the group.
 *
 * A preset outside the composition the portal offers - absent, or switched off - is
 * {@see NotFoundException} instead: a preset a client was never offered is a preset it cannot choose, and
 * an answer describing it would offer it.
 */
final class PresetProvider
{
	/**
	 * Contacts and companies have no categories of their own, so the read right of the owner type is
	 * always asked in the default category - the same zero legacy asks it in.
	 */
	private const OWNER_CATEGORY_ID = 0;

	private readonly PresetResolver $presets;

	private readonly OwnerResolver $owners;

	public function __construct(private readonly int $userId)
	{
		$this->presets = new PresetResolver();
		$this->owners = new OwnerResolver();
	}

	/**
	 * A single preset of the composition the portal offers, answering whether it is the default one of
	 * $ownerTypeId.
	 *
	 * @throws NotFoundException there is no such preset, or the portal no longer offers it.
	 * @throws AccessDeniedException the user may not read the entities of this owner type.
	 * @throws ArgumentException an owner type no requisite belongs to. It is the invariant of the caller
	 *         rather than something a client states, so a wrong one is a defect of the calling code.
	 * @throws SystemException a failure of the storage.
	 */
	public function getById(int $ownerTypeId, int $presetId): Preset
	{
		$this->requireReadableOwnerType($ownerTypeId);

		$preset = $this->presets->findAvailable($presetId);
		if ($preset === null)
		{
			throw new NotFoundException(RequisiteError::PRESET_NOT_FOUND);
		}

		// The mark is raised on a preset of the composition alone, and this one is of it: what
		// `findAvailable()` answers is what the portal offers. A preset the setting of the portal names
		// but the composition no longer holds is never reached here - it is the absence above.
		$defaultPresetId = $this->presets->resolveDefaultPresetId($ownerTypeId);

		return self::toPublicPreset(
			$preset->withIsDefault($defaultPresetId > 0 && $preset->id === $defaultPresetId),
		);
	}

	/**
	 * The presets the portal offers, ordered as asked, each of them answering whether it is the default
	 * one of $ownerTypeId.
	 *
	 * @param array<string, string> $order `id` or `sort` => `ASC`/`DESC` - the two fields the contract of
	 *        a preset declares sortable, named as it names them. The ordering is part of the query the
	 *        presets are chosen by rather than something done to the answer, and the identifier settles
	 *        whatever it leaves equal, so two presets of one sort keep their places between two identical
	 *        reads. An ordering that names nothing is the order the interface shows the presets in - by
	 *        sort, then by identifier - which is the answer this read has always given.
	 * @return Preset[]
	 * @throws AccessDeniedException the user may not read the entities of this owner type.
	 * @throws ArgumentException an owner type no requisite belongs to, or an ordering over a field that
	 *         is none of the two. A field the contract does not offer to order by is refused rather than
	 *         ignored: an answer in an order the client did not ask for is the mistake hardest to notice.
	 * @throws SystemException a failure of the storage.
	 */
	public function getList(int $ownerTypeId, array $order = []): array
	{
		$this->requireReadableOwnerType($ownerTypeId);

		return array_map(self::toPublicPreset(...), $this->presets->listPresets($ownerTypeId, $order));
	}

	/**
	 * The one decision about rights the group makes outside
	 * {@see \Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions}, which holds the four about a
	 * requisite record. This one is about a type of owner and names no record, and it has this read alone
	 * for a caller; the docblock there says so and says what would make it worth moving.
	 *
	 * @throws AccessDeniedException
	 * @throws ArgumentException
	 */
	private function requireReadableOwnerType(int $ownerTypeId): void
	{
		if (!$this->owners->isSupportedType($ownerTypeId))
		{
			throw new ArgumentException('Requisites do not belong to owners of this type', 'ownerTypeId');
		}

		$canRead = Container::getInstance()
			->getUserPermissions($this->userId)
			->entityType()
			->canReadItemsInCategory($ownerTypeId, self::OWNER_CATEGORY_ID)
		;

		if (!$canRead)
		{
			throw new AccessDeniedException();
		}
	}

	private static function toPublicPreset(InternalPreset $preset): Preset
	{
		return new Preset(
			$preset->id,
			$preset->name,
			$preset->isDefault,
			$preset->sort,
			self::toPublicFields($preset),
		);
	}

	/**
	 * The composition as the group publishes it: a field of the contract, a user field of the portal or
	 * the marker of the address, each told apart by the marks the published entry carries.
	 *
	 * AN ENTRY THAT NAMES NONE OF THE THREE IS LEFT OUT, and there are two ways to name none. One is a
	 * name left in the setting of a preset by a field that no longer exists - the internal composition
	 * carries it rather than dropping it, because what a published composition may leave out is decided
	 * by the read that publishes it. The other is a field of the storage the contract of this group does
	 * not carry: a preset may well show the signature and the stamp of a requisite, and neither is a
	 * field the group answers with or takes in a write. Publishing either would send a client looking for
	 * a field of a requisite that has none.
	 *
	 * @return PresetField[]
	 */
	private static function toPublicFields(InternalPreset $preset): array
	{
		$fields = [];
		foreach ($preset->fields as $field)
		{
			if ($field->isAddress)
			{
				// The address of a requisite is a record of its own, so the entry that says the preset
				// shows it names no field at all.
				$fields[] = self::toPublicField('', true, false, $field);
			}
			elseif ((string)$field->userField !== '')
			{
				$fields[] = self::toPublicField((string)$field->userField, false, true, $field);
			}
			elseif ($field->field !== null && isset(RequisiteColumnMap::COLUMN_BY_FIELD[$field->field]))
			{
				$fields[] = self::toPublicField($field->field, false, false, $field);
			}
		}

		return $fields;
	}

	private static function toPublicField(
		string $name,
		bool $isAddress,
		bool $isUserField,
		InternalPresetField $field,
	): PresetField
	{
		return new PresetField($name, $isAddress, $isUserField, $field->title, $field->inShortList, $field->sort);
	}
}
