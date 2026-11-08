<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Location;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Address\AddressPlacement;
use Bitrix\Location\Entity\Address;
use Bitrix\Location\Entity\Address\AddressLink;
use Bitrix\Location\Entity\Address\AddressLinkCollection;
use Bitrix\Location\Entity\Address\FieldType;
use Bitrix\Location\Entity\Address\IAddressLink;
use Bitrix\Location\Service\AddressService;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use CCrmOwnerType;

/**
 * The geodata behind the flat columns of a crm address: the address of the location module the row is
 * linked to.
 *
 * The row itself keeps nothing but the identifier of that address, and everything the identifier costs
 * lives here. An address is created from the flat fields, reused when nobody else refers to it, copied
 * when somebody does, and released together with the row that referred to it. Two owners that happen to
 * sit at one physical address must never share one record - editing the address of one would silently
 * edit the address of the other - so sharing is resolved by copying, and what tells a shared address
 * from a free one are the links the address carries.
 *
 * A link names the record the crm address is stored on, so where that record is decides both what is
 * written and what is read back. For a contact and a company the address lives on one of their requisites
 * and the link names the requisite, while the question the class really answers about a link is a different
 * one: does it belong to a row of *this* owner - which takes the requisite the link names back to the
 * contact or the company behind it. For a lead, a requisite and every other owner the record is the owner
 * itself, so the link names it directly and no such detour is needed. That is the one difference between
 * the two, and {@see AddressPlacement::isKeptOnARequisite()} is where it is decided.
 *
 * The module is optional. Where it is not installed the row is stored without geodata at all: every
 * method answers as if the row simply had no link, and no address operation fails because of it.
 *
 * Nothing of the location module leaves this class. Callers pass and take back the flat fields of the
 * crm address together with `LOC_ADDR_ID`, exactly as the storage of the crm module holds them. Inbound
 * there is one exception: the old entry point of an address is handed a whole address of the location
 * module by its own callers and passes it on to {@see self::exportGivenAddress()}, which answers with those
 * same flat fields.
 *
 * The class is left open for one reason: {@see self::isAvailable()} is the only way to run an address
 * operation against a missing module on a stand where the module is installed.
 *
 * @internal
 */
class AddressLinkGateway
{
	private const MODULE_ID = 'location';

	/** `CRM_<owner>_ADDRESS` - the entity type of every link the crm module writes. */
	private const LINK_ENTITY_TYPE_PATTERN = '/^CRM_(\w+)_ADDRESS$/';

	/** `<address type>.<record type>.<record>` - the entity identifier of such a link. */
	private const LINK_ENTITY_ID_PATTERN = '/^(\d+)\.(\d+)\.(\d+)$/';

	/** `b_location_addr_fld` stores a field value as `VARCHAR(1024)`. */
	private const MAX_FIELD_LENGTH = 1024;

	private ?bool $isModuleIncluded = null;

	/**
	 * The address the caller brought as an object instead of an identifier, waiting for the record it
	 * belongs to, {@see self::exportGivenAddress()}.
	 */
	private ?Address $givenAddress = null;

	/**
	 * The address the row is about to store: created, reused or copied, and linked to the record the row
	 * is stored on.
	 *
	 * The flat fields are written into the linked address, and only the ones that came: a field left out
	 * keeps whatever the address holds. The other direction - a link that arrived without a single flat
	 * field of its own - belongs to {@see self::exportFields()} and has to be settled before the caller
	 * decides whether the address is empty at all.
	 *
	 * @param array<string, mixed> $addressFields Flat fields of the crm address and `LOC_ADDR_ID`.
	 * @param int $previousLocationAddressId Address the row referred to before this write, 0 when none.
	 * @param string|null $languageId Language a newly created address is written in; `null` takes the
	 *        default one of the portal. An address that already exists keeps the language it has.
	 * @return array<string, mixed> The same fields with `LOC_ADDR_ID` set to the address the row must
	 *         store, 0 when the module is not available.
	 * @throws SystemException When the location module refuses to store the address.
	 */
	public function bindToRecord(
		AddressPlacement $placement,
		array $addressFields,
		int $previousLocationAddressId,
		?string $languageId = null,
	): array
	{
		if (!$this->isAvailable())
		{
			$addressFields['LOC_ADDR_ID'] = 0;

			return $addressFields;
		}

		// An address the caller brought as an object of its own wins over any identifier: it is the address
		// the write is about, changes and all, and it may well have no identifier yet.
		$address = $this->takeGivenAddress();
		if ($address === null)
		{
			$locationAddressId = (int)($addressFields['LOC_ADDR_ID'] ?? 0);
			if ($locationAddressId <= 0)
			{
				// no address named: the row goes on with the one it already refers to
				$locationAddressId = $previousLocationAddressId;
				$previousLocationAddressId = 0;
			}

			$address = $locationAddressId > 0 ? Address::load($locationAddressId) : null;
		}

		if (!$address instanceof Address)
		{
			$address = new Address($languageId ?? EntityAddress::getDefaultLanguageId());
		}

		self::writeFields($address, $addressFields);

		if ($previousLocationAddressId > 0 && $previousLocationAddressId !== $address->getId())
		{
			$this->release(
				$previousLocationAddressId,
				$placement->typeId,
				$placement->ownerTypeId,
				$placement->ownerId,
			);
		}

		$addressFields['LOC_ADDR_ID'] = $this->link($address, $placement);

		return $addressFields;
	}

	/**
	 * The flat fields of the address a bare link points at.
	 *
	 * A caller that named a link and not a single field of its own is asking for the address of the
	 * location module, and the fields of the crm row are filled from it. This has to happen before the
	 * caller looks at the fields: an address that arrived as a link alone is not an empty address.
	 *
	 * @param array<string, mixed> $addressFields Flat fields of the crm address and `LOC_ADDR_ID`.
	 * @return array<string, mixed> The same fields, filled in from the linked address when there was
	 *         nothing to fill them from the caller.
	 */
	public function exportFields(array $addressFields): array
	{
		$locationAddressId = (int)($addressFields['LOC_ADDR_ID'] ?? 0);
		if (!$this->isAvailable() || $locationAddressId <= 0 || EntityAddress::isSet($addressFields))
		{
			return $addressFields;
		}

		$address = Address::load($locationAddressId);

		return $address instanceof Address
			? array_replace($addressFields, self::readFields($address))
			: $addressFields
		;
	}

	/**
	 * The flat fields of an address of the location module the caller brought as an object of its own,
	 * together with the identifier the row has to store.
	 *
	 * The old input of a crm address carries its geodata either as an identifier or as a whole address of
	 * the location module built by the caller - from the json of the autocomplete, from a converted
	 * requisite, from a business process. A row stores an identifier and nothing else, so the object itself
	 * is kept until the write knows which record it belongs to, and {@see self::bindToRecord()} takes it from
	 * here instead of loading an address by an identifier.
	 *
	 * Nothing is stored at this point, and that is the whole reason the object is kept rather than saved: an
	 * object the caller loaded from an address of another record and changed would have its changes written
	 * into that very address, and only afterwards would the copying below notice the foreign link and leave
	 * the original behind - already changed. Copying comes before storing, exactly as it did on the old write
	 * path, and an address that has no identifier yet gets one there too.
	 *
	 * The fields of the object are the answer, and the caller lets them win over the flat fields it was
	 * given beside the object - that is how the old write path settled the conflict of the two. The link of
	 * the row is not written here either: which record the row lives on is not decided yet.
	 *
	 * @param mixed $locationAddress Value of the old `LOC_ADDR` input: an address of the location module or
	 *        anything else.
	 * @return array<string, mixed> Flat fields of the address and `LOC_ADDR_ID`; empty when the value is no
	 *         address of the location module or the module is not available. The identifier is a zero for an
	 *         address the caller built from scratch - the object carries what the row will store.
	 */
	public function exportGivenAddress(mixed $locationAddress): array
	{
		if (!$this->isAvailable() || !$locationAddress instanceof Address)
		{
			return [];
		}

		$this->givenAddress = $locationAddress;

		return array_merge(
			self::readFields($locationAddress),
			['LOC_ADDR_ID' => $locationAddress->getId()],
		);
	}

	/**
	 * The address handed over by {@see self::exportGivenAddress()}, once: a second write through the same
	 * gateway starts from the fields of the row again and not from an object of a write that is over.
	 */
	private function takeGivenAddress(): ?Address
	{
		$givenAddress = $this->givenAddress;
		$this->givenAddress = null;

		return $givenAddress;
	}

	/**
	 * The row is not going to keep the address after all: the link of the row goes away, and the address
	 * goes with its last link. An address somebody else still refers to stays where it is.
	 *
	 * The owner is named instead of the record on purpose: which links belong to a row of this owner is a
	 * question of the owner alone, and the answer is needed where no record has been resolved yet.
	 *
	 * @throws SystemException When the location module refuses to store the address.
	 */
	public function release(int $locationAddressId, int $typeId, int $ownerTypeId, int $ownerId): void
	{
		if (!$this->isAvailable() || $locationAddressId <= 0)
		{
			return;
		}

		$address = Address::load($locationAddressId);
		if (!$address instanceof Address)
		{
			return;
		}

		$isModified = $this->dropOwnLinks($address, $typeId, $ownerTypeId, $ownerId);
		if (!$address->hasLinks())
		{
			$address->delete();

			return;
		}

		if ($isModified)
		{
			self::save($address);
		}
	}

	/**
	 * Deletes the address whatever else refers to it. Deleting a crm address does exactly that today,
	 * foreign links included, and the operation keeps that behaviour instead of inventing a safer one of
	 * its own.
	 */
	public function delete(int $locationAddressId): void
	{
		if (!$this->isAvailable() || $locationAddressId <= 0)
		{
			return;
		}

		$address = Address::load($locationAddressId);
		if ($address instanceof Address)
		{
			$address->delete();
		}
	}

	protected function isAvailable(): bool
	{
		$this->isModuleIncluded ??= Loader::includeModule(self::MODULE_ID);

		return $this->isModuleIncluded;
	}

	/**
	 * @return int Identifier of the address the row must store.
	 * @throws SystemException
	 */
	private function link(Address $address, AddressPlacement $placement): int
	{
		$this->dropOwnLinks($address, $placement->typeId, $placement->ownerTypeId, $placement->ownerId);
		if ($address->hasLinks())
		{
			// somebody else refers to it, so the row gets a copy instead of a share
			$address->setId(0);
		}

		$linkEntityId = self::buildLinkEntityId($placement->typeId, $placement->recordTypeId, $placement->recordId);
		$linkEntityType = self::buildLinkEntityType($placement->recordTypeId);
		$address->setLinks(new AddressLinkCollection([new AddressLink($linkEntityId, $linkEntityType)]));
		self::save($address);

		$this->dropLinkFromOtherAddresses($address->getId(), $linkEntityId, $linkEntityType);

		return $address->getId();
	}

	/**
	 * A copy leaves the original still claiming to be the address of this row, and so does a write
	 * interrupted halfway. One row refers to one address: every other address holding the link of this
	 * row loses it, and an address left without links goes away.
	 *
	 * @throws SystemException
	 */
	private function dropLinkFromOtherAddresses(
		int $keptAddressId,
		string $linkEntityId,
		string $linkEntityType,
	): void
	{
		$addresses = AddressService::getInstance()->findByLinkedEntity($linkEntityId, $linkEntityType);

		/** @var Address $address */
		foreach ($addresses as $address)
		{
			if ($address->getId() === $keptAddressId)
			{
				continue;
			}

			$links = $address->getLinks();
			$remaining = new AddressLinkCollection();
			foreach ($links as $link)
			{
				if ($link->getAddressLinkEntityType() !== $linkEntityType
					|| $link->getAddressLinkEntityId() !== $linkEntityId)
				{
					$remaining->addItem($link);
				}
			}

			if ($remaining->count() === $links->count())
			{
				continue;
			}

			$address->setLinks($remaining);
			if ($address->hasLinks())
			{
				self::save($address);
			}
			else
			{
				$address->delete();
			}
		}
	}

	/**
	 * Removes from the address every link a row of this owner may hold: the link of the row itself and
	 * links left pointing at a record or an owner that is gone. What stays belongs to somebody else,
	 * and to somebody else the row may do nothing at all.
	 *
	 * @return bool Whether anything was removed.
	 */
	private function dropOwnLinks(Address $address, int $typeId, int $ownerTypeId, int $ownerId): bool
	{
		$links = $address->getLinks();
		$requisiteOwners = $this->resolveRequisiteOwners($links);
		$remaining = new AddressLinkCollection();

		foreach ($links as $link)
		{
			if (!$this->isLinkToDrop($link, $typeId, $ownerTypeId, $ownerId, $requisiteOwners))
			{
				$remaining->addItem($link);
			}
		}

		if ($remaining->count() === $links->count())
		{
			return false;
		}

		$address->setLinks($remaining);

		return true;
	}

	/**
	 * A link this row may take away: the link of the row itself, or one left pointing at a record or an
	 * owner that is gone.
	 *
	 * @param array<int, array{0: int, 1: int}> $requisiteOwners
	 */
	private function isLinkToDrop(
		IAddressLink $link,
		int $typeId,
		int $ownerTypeId,
		int $ownerId,
		array $requisiteOwners,
	): bool
	{
		if (preg_match(self::LINK_ENTITY_TYPE_PATTERN, $link->getAddressLinkEntityType()) !== 1)
		{
			// another module wrote it
			return false;
		}

		$identity = self::parseLink($link);
		if ($identity === null)
		{
			// a link of the crm module no address row could have written
			return true;
		}

		[$linkTypeId, $linkRecordTypeId, $linkRecordId] = $identity;

		if (!AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			if ($linkTypeId === $typeId && $linkRecordTypeId === $ownerTypeId && $linkRecordId === $ownerId)
			{
				return true;
			}

			return !$this->recordExists($linkRecordTypeId, $linkRecordId, $requisiteOwners);
		}

		if ($linkRecordTypeId !== CCrmOwnerType::Requisite)
		{
			// a link of the crm module a row of a contact or a company could not have written
			return true;
		}

		$owner = $requisiteOwners[$linkRecordId] ?? null;
		if ($owner === null)
		{
			// the requisite the link names is gone
			return true;
		}

		if ($linkTypeId === $typeId && $owner === [$ownerTypeId, $ownerId])
		{
			return true;
		}

		return !self::ownerExists($owner[0], $owner[1]);
	}

	/**
	 * The owners behind every requisite the links name, read at once. A requisite the answer leaves out
	 * no longer exists.
	 *
	 * @return array<int, array{0: int, 1: int}> Requisite => owner type and owner.
	 */
	private function resolveRequisiteOwners(AddressLinkCollection $links): array
	{
		$requisiteIds = [];
		foreach ($links as $link)
		{
			$identity = self::parseLink($link);
			if ($identity !== null && $identity[1] === CCrmOwnerType::Requisite)
			{
				$requisiteIds[] = $identity[2];
			}
		}

		if ($requisiteIds === [])
		{
			return [];
		}

		$rows = RequisiteTable::query()
			->setSelect(['ID', 'ENTITY_TYPE_ID', 'ENTITY_ID'])
			->whereIn('ID', array_values(array_unique($requisiteIds)))
			->fetchAll()
		;

		$owners = [];
		foreach ($rows as $row)
		{
			$owners[(int)$row['ID']] = [(int)$row['ENTITY_TYPE_ID'], (int)$row['ENTITY_ID']];
		}

		return $owners;
	}

	/**
	 * @return array{0: int, 1: int, 2: int}|null Address type and the record of the link, `null` when the
	 *         link names anything no address row could have written.
	 */
	private static function parseLink(IAddressLink $link): ?array
	{
		$entityType = [];
		if (preg_match(self::LINK_ENTITY_TYPE_PATTERN, $link->getAddressLinkEntityType(), $entityType) !== 1)
		{
			return null;
		}

		// Two names name a record of an address: the requisite a contact, a company or a requisite keeps
		// the row on, and the lead that keeps the row on itself. Nothing else is a link of an address row.
		$linkOwnerTypeId = CCrmOwnerType::ResolveID($entityType[1]);
		if ($linkOwnerTypeId !== CCrmOwnerType::Requisite && $linkOwnerTypeId !== CCrmOwnerType::Lead)
		{
			return null;
		}

		$identity = [];
		if (preg_match(self::LINK_ENTITY_ID_PATTERN, $link->getAddressLinkEntityId(), $identity) !== 1)
		{
			return null;
		}

		$typeId = (int)$identity[1];
		$recordTypeId = (int)$identity[2];
		$recordId = (int)$identity[3];

		if (!EntityAddressType::isDefined($typeId)
			|| !CCrmOwnerType::IsDefined($recordTypeId)
			|| $recordId <= 0)
		{
			return null;
		}

		return [$typeId, $recordTypeId, $recordId];
	}

	/**
	 * Whether the record a link names is still there. A requisite is not an entity the owner registry
	 * knows, so it is answered by the requisites already read for the links of this address.
	 *
	 * @param array<int, array{0: int, 1: int}> $requisiteOwners
	 */
	private function recordExists(int $recordTypeId, int $recordId, array $requisiteOwners): bool
	{
		if ($recordTypeId === CCrmOwnerType::Requisite)
		{
			return isset($requisiteOwners[$recordId]);
		}

		return self::ownerExists($recordTypeId, $recordId);
	}

	private static function ownerExists(int $entityTypeId, int $entityId): bool
	{
		$info = [];

		return $entityId > 0
			&& CCrmOwnerType::IsDefined($entityTypeId)
			&& CCrmOwnerType::TryGetInfo($entityTypeId, $entityId, $info) === true
		;
	}

	/**
	 * Only a field that came as a string is written, and an empty one only over a value that is really
	 * there: that is what makes a write of one field a write of one field.
	 *
	 * @param array<string, mixed> $addressFields
	 */
	private static function writeFields(Address $address, array $addressFields): void
	{
		foreach (self::getFieldTypeMap() as $addressField => $fieldType)
		{
			$value = $addressFields[$addressField] ?? null;
			if (!is_string($value))
			{
				continue;
			}

			$value = mb_substr($value, 0, self::MAX_FIELD_LENGTH);
			$storedValue = $address->getFieldValue($fieldType);
			if ($value !== '' || ($storedValue !== null && $storedValue !== $value))
			{
				$address->setFieldValue($fieldType, $value);
			}
		}
	}

	/**
	 * @return array<string, string|null> Flat fields of the crm address as the linked address holds them.
	 */
	private static function readFields(Address $address): array
	{
		$addressFields = [];
		foreach (self::getFieldTypeMap() as $addressField => $fieldType)
		{
			$addressFields[$addressField] = $address->getFieldValue($fieldType);
		}

		return $addressFields;
	}

	/**
	 * The country code is left out on purpose: the location module has no field of its own for it.
	 *
	 * @return array<string, int>
	 */
	private static function getFieldTypeMap(): array
	{
		return [
			'ADDRESS_1' => FieldType::ADDRESS_LINE_1,
			'ADDRESS_2' => FieldType::ADDRESS_LINE_2,
			'CITY' => FieldType::LOCALITY,
			'POSTAL_CODE' => FieldType::POSTAL_CODE,
			'PROVINCE' => FieldType::ADM_LEVEL_1,
			'REGION' => FieldType::ADM_LEVEL_2,
			'COUNTRY' => FieldType::COUNTRY,
		];
	}

	private static function buildLinkEntityType(int $entityTypeId): string
	{
		return 'CRM_' . CCrmOwnerType::ResolveName($entityTypeId) . '_ADDRESS';
	}

	private static function buildLinkEntityId(int $typeId, int $entityTypeId, int $entityId): string
	{
		return $typeId . '.' . $entityTypeId . '.' . $entityId;
	}

	/**
	 * @throws SystemException
	 */
	private static function save(Address $address): void
	{
		$result = $address->save();
		if (!$result->isSuccess())
		{
			throw new SystemException(implode(PHP_EOL, $result->getErrorMessages()));
		}
	}
}
