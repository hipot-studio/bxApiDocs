<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\V2\Internal\Access\Address\OwnerAddressPermissions;
use Bitrix\Crm\V2\Public\Entity\Address\AddressIdentifier;
use Bitrix\Crm\V2\Internal\Entity\Address\AddressPlacement;
use Bitrix\Crm\V2\Internal\Integration\Location\AddressLinkGateway;
use Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository;
use Bitrix\Crm\V2\Public\Command\Address\OwnerAddressError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DB\DuplicateEntryException;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * Adding, changing, deleting and reading an address of a CRM owner.
 *
 * Where the row lives depends on the owner, and that one choice is what the whole write path branches on -
 * {@see AddressPlacement}. An address of a contact or a company is never stored on its owner: it lives on
 * one of the requisites of the owner, and the owner itself stays in the anchor columns of the row. Adding
 * such an address is therefore not a single insert - it resolves the target requisite, may create one, may
 * move the default requisite of the owner. Every
 * other owner keeps the row on itself, and there is no requisite in the picture at all. Everything else
 * about a write is the same for all of them, and the four places the two ways differ are named:
 * {@see self::resolvePlacement()}, {@see self::findPreviousRow()}, {@see self::resolveAnchor()} and
 * {@see self::resolveDefaultFlag()}.
 *
 * Changing and deleting work on a row that is already known: they resolve nothing and move no flags.
 *
 * The class has two entry lines and they are not interchangeable:
 *
 * - the plain methods check the rights of the owner and are the ones the public contract and REST use;
 *   they serve a contact and a company alone, which is the answer of {@see OwnerAddressPermissions};
 * - the methods suffixed with `WithoutPermissionCheck` check nothing, serve every CRM owner type and exist
 *   for one caller only - the facade of the legacy address class. Writing an address does not check rights
 *   today, and routing the old entry point through a checking operation would break conversion, import and
 *   web forms. The line is closed for new consumers and goes away once every legacy consumer is moved onto
 *   the checking one. The check of the owner arguments of that line is published beside them,
 *   {@see self::assertOwnerIsDefined()}, and a facade calls it before it parses its own input.
 *
 * The two lines differ in more than the rights. The checking line rejects an empty address as a domain
 * error and inserts, so writing a type already taken in the requisite is a conflict. The facade line
 * keeps what the old code does: an empty address blanks the existing row instead of failing, the write
 * merges by the unique key, silently overwriting a row of the same type, and the anchor, the default flag
 * and the language of the geodata are read from the input instead of being decided by the owner alone.
 *
 * Rights are answered by {@see OwnerAddressPermissions}, storage by {@see AddressRepository}, the
 * choice of the requisite by {@see RequisiteResolver}, the side effects by {@see AddressEffects} and the
 * geodata of the address by {@see AddressLinkGateway}.
 * A denial, an anchor pointing at another owner and a missing row all leave through the same
 * {@see ObjectNotFoundException}: from the outside they must be indistinguishable.
 *
 * @internal
 */
final class OwnerAddressService
{
	/** Identifier of the address, int. */
	public const ADDRESS_ID = 'addressId';

	/** Requisite the address is stored on, int. */
	public const REQUISITE_ID = 'requisiteId';

	/** Whether the facade write touched the storage at all, bool. */
	public const IS_WRITTEN = 'isWritten';

	/**
	 * The address is empty, and an empty address is not a value the checking line stores.
	 *
	 * The code is a published one and is declared by {@see OwnerAddressError}; the name below is what the
	 * callers inside the layer use, and it carries no value of its own.
	 */
	public const ERROR_ADDRESS_IS_EMPTY = OwnerAddressError::ADDRESS_IS_EMPTY->value;

	/** The target requisite already holds an address of this type, {@see OwnerAddressError}. */
	public const ERROR_ADDRESS_TYPE_OCCUPIED = OwnerAddressError::ADDRESS_TYPE_OCCUPIED->value;

	/**
	 * The code of the country does not hold up against the country of the address, {@see OwnerAddressError}.
	 *
	 * This is the checking half of the rule the facade line normalizes silently: the very value the old
	 * entry point drops is the value a caller who can be answered is refused.
	 */
	public const ERROR_ADDRESS_COUNTRY_CODE_MISMATCH = OwnerAddressError::ADDRESS_COUNTRY_CODE_MISMATCH->value;

	private const STORED_FIELDS = 'storedFields';

	private const PLACEMENT = 'placement';

	private const RESET_DEFAULT_REQUISITE = 'resetDefaultRequisite';

	private const ADDRESS_FIELDS = [
		'ADDRESS_1',
		'ADDRESS_2',
		'CITY',
		'POSTAL_CODE',
		'REGION',
		'PROVINCE',
		'COUNTRY',
		'COUNTRY_CODE',
	];

	/**
	 * Fields of the input that shape the row instead of being stored as they came: the anchor columns, the
	 * default flag and the language a newly created address of the location module is written in.
	 *
	 * Only the facade line ever passes them on. The checking operations leave every one of them to the
	 * rules of the owner - see {@see self::resolveAnchor()}, {@see self::resolveDefaultFlag()} and
	 * {@see AddressLinkGateway::bindToRecord()}.
	 */
	private const ROW_SHAPE_FIELDS = ['ANCHOR_TYPE_ID', 'ANCHOR_ID', 'IS_DEF', 'LANGUAGE_ID'];

	/**
	 * The three values that raise the default flag of an address of a requisite, compared and never
	 * interpreted: `1` as an integer and `'y'` in lower case are not among them.
	 */
	private const DEFAULT_FLAG_INPUT_VALUES = [true, 'Y', '1'];

	private readonly AddressStorage $storage;

	private readonly RequisiteResolver $requisiteResolver;

	private readonly AddressEffects $effects;

	private readonly AddressLinkGateway $locationLink;

	public function __construct(
		?AddressRepository $repository = null,
		?RequisiteResolver $requisiteResolver = null,
		?AddressEffects $effects = null,
		?AddressLinkGateway $locationLink = null,
	)
	{
		$this->storage = new AddressStorage($repository ?? new AddressRepository());
		$this->requisiteResolver = $requisiteResolver ?? new RequisiteResolver();
		$this->effects = $effects ?? new AddressEffects();
		$this->locationLink = $locationLink ?? new AddressLinkGateway();
	}

	// region operations checking the rights of the owner

	/**
	 * Adds an address to the owner and makes it the default one of its type.
	 *
	 * @param array<string, mixed> $fields Address fields, {@see self::ADDRESS_FIELDS} plus `LOC_ADDR_ID`.
	 * @param int|null $userId `null` means the current user.
	 * @return Result Data of a successful result: `[self::ADDRESS_ID => int]`. An unsuccessful result
	 *         carries a domain error: an empty address, a code of a country that does not hold up against
	 *         the country beside it, a type already taken in the target requisite or an owner left without
	 *         a requisite.
	 * @throws ObjectNotFoundException When the owner does not exist or the user may not change it.
	 */
	public function add(int $ownerTypeId, int $ownerId, int $typeId, array $fields, ?int $userId = null): Result
	{
		$this->assertCanWriteOwner($ownerTypeId, $ownerId, $userId);

		$addressFields = self::extractAddressFields($fields);
		if (EntityAddress::isEmpty($addressFields))
		{
			return self::emptyAddressResult();
		}

		if (CountryCodeConsistency::isRefused($addressFields))
		{
			return self::countryCodeMismatchResult();
		}

		$writeResult = $this->writeAddress(
			$ownerTypeId,
			$ownerId,
			$typeId,
			$addressFields,
			overwriteOccupiedType: false,
			// An owner already keeping a default address of this type gets no second one: the write
			// lands on the very requisite holding it and leaves as a conflict. There is therefore
			// neither a previous row to inherit a flag from nor an address of the location module to
			// take over here.
			previousLocationAddressId: 0,
			previousIsDefault: false,
		);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$writtenData = $writeResult->getData();
		$this->effects->runAfterWrite($ownerTypeId, $ownerId, $writtenData[self::STORED_FIELDS]);

		return (new Result())->setData([self::ADDRESS_ID => $writtenData[self::ADDRESS_ID]]);
	}

	/**
	 * Rewrites the address fields of a known row. The type of the address, its requisite and its default
	 * flag stay as they are: changing an address neither resolves a requisite anew nor moves the flag.
	 *
	 * Fields that are not passed keep their stored values; blanking every field at once is refused, an
	 * empty address is not a result of a change either. A change that asks for the values the row already
	 * holds writes nothing at all, and it answers so only once the row has been found and the rights of
	 * its owner have been answered for - a row that is not there and a row of another owner are still no
	 * row at all.
	 *
	 * The code of the country is answered for by the address the row would hold after the change, so a
	 * change naming the code alone is refused or accepted by the country that is already stored.
	 *
	 * @param array<string, mixed> $fields Address fields to change.
	 * @param int $ownerId Identifier of the owner, `0` for any owner of this type,
	 *        {@see OwnerAddressPermissions::canWriteAddress()}.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user, the shape
	 *         of the row included: a row of a shape this owner could not have written is not its row.
	 */
	public function update(int $addressId, int $ownerTypeId, int $ownerId, array $fields, ?int $userId = null): Result
	{
		$row = $this->requireAddressToWrite($addressId, $ownerTypeId, $ownerId, $userId);
		$ownerId = (int)$row['ANCHOR_ID'];

		$placement = self::resolvePlacementOfStoredRow($row, $ownerTypeId, $ownerId);
		if ($placement === null)
		{
			throw new ObjectNotFoundException("Address not found: {$addressId}");
		}

		$storedAddressFields = self::extractAddressFields($row);
		$addressFields = array_merge($storedAddressFields, self::extractAddressFields($fields));
		if (EntityAddress::isEmpty($addressFields))
		{
			return self::emptyAddressResult();
		}

		if (self::isSameAddress($addressFields, $storedAddressFields))
		{
			return new Result();
		}

		// The address the row would hold afterwards is what answers for the code, not the fields the change
		// names: a change naming the code alone is answered by the country already stored, and a change that
		// asks for nothing new is not refused for a pair the row was written with before this line existed.
		if (CountryCodeConsistency::isRefused($addressFields))
		{
			return self::countryCodeMismatchResult();
		}

		$writeResult = $this->rewriteRow($placement, $row, $addressFields);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$this->effects->runAfterWrite($ownerTypeId, $ownerId, $writeResult->getData()[self::STORED_FIELDS]);

		return new Result();
	}

	/**
	 * Deletes a known row together with the geodata behind it. The default flag is not moved onto another
	 * address of the owner: the operation removes an address, it does not choose a new main one.
	 *
	 * Removing an address is where the boundary of this work runs. {@see EntityAddress::unregister()} was
	 * left removing a row itself and delegates to nothing here, so the two removals are two independent
	 * implementations of one rule, kept in step by hand rather than by one standing behind the other.
	 * Two things about this one are therefore decided rather than left out, and both are what the old entry
	 * point does. It runs without a transaction of its own - the geodata, the row and the effects go one
	 * after another, the way the old entry point removes them. And it takes the row as it is stored: the
	 * key it deletes by is built from the columns of the row and the shape of the row is not questioned, so
	 * a row that {@see self::update()} refuses as one this owner could not have written is still a row that
	 * can be removed. Refusing to remove it would leave a row nothing can repair.
	 *
	 * @param int $ownerId Identifier of the owner, `0` for any owner of this type,
	 *        {@see OwnerAddressPermissions::canWriteAddress()}.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user.
	 */
	public function delete(int $addressId, int $ownerTypeId, int $ownerId, ?int $userId = null): Result
	{
		$row = $this->requireAddressToWrite($addressId, $ownerTypeId, $ownerId, $userId);
		$ownerId = (int)$row['ANCHOR_ID'];
		$addressKey = self::buildAddressKey($row);

		$this->effects->publishBeforeDelete($addressKey);
		$this->locationLink->delete($row['LOC_ADDR_ID']);

		$deleteResult = $this->storage->deleteRow($row['TYPE_ID'], $row['ENTITY_TYPE_ID'], $row['ENTITY_ID']);
		$this->effects->runAfterDelete($ownerTypeId, $ownerId, $addressKey, $deleteResult->isSuccess());

		return $deleteResult;
	}

	/**
	 * One address of the owner by its identifier.
	 *
	 * @param int $ownerId Identifier of the owner, `0` for any owner of this type,
	 *        {@see OwnerAddressPermissions::canReadAddress()}.
	 * @return array<string, mixed> Row as {@see AddressRepository::findById()} returns it.
	 * @throws ObjectNotFoundException When there is no such address of this owner for this user.
	 */
	public function get(int $addressId, int $ownerTypeId, int $ownerId, ?int $userId = null): array
	{
		$row = $this->storage->findRowById($addressId);
		if ($row === null || !$this->getPermissions($userId)->canReadAddress($row, $ownerTypeId, $ownerId))
		{
			throw new ObjectNotFoundException("Address not found: {$addressId}");
		}

		return $row;
	}

	/**
	 * All addresses of the owner, in no particular order: the caller that needs one applies it itself.
	 *
	 * @return array<int, array<string, mixed>> Rows as the repository returns them.
	 * @throws ObjectNotFoundException When the owner does not exist or the user may not read it.
	 */
	public function getAllByOwner(int $ownerTypeId, int $ownerId, ?int $userId = null): array
	{
		$this->assertCanReadOwner($ownerTypeId, $ownerId, $userId);

		return $this->storage->findRowsByOwner($ownerTypeId, $ownerId);
	}

	// endregion

	// region operations for the legacy facade, no rights are checked

	/**
	 * Refuses an owner that is no entity at all: the facade line serves an address of any CRM owner, so
	 * this is the only thing left to check about one. The two failures are told apart on purpose - an owner
	 * type outside the range of entity types and an identifier that is not an identifier are different
	 * mistakes, and the old entry point reports them as different exceptions. The operation moving the
	 * default flag names a record with the very same pair of arguments and checks it by the very same rule.
	 *
	 * The check is published for the facades of the old address class alone. They call it before they touch
	 * the transport form of their input, because parsing that form already writes: geodata arriving as an
	 * object of the location module is stored the moment it is read, and a call that is going to be refused
	 * must leave nothing behind. That is why the rule lives here and not inside every operation of the
	 * line - one place, called from the outside, instead of a second copy per facade.
	 *
	 * Both refusals keep the argument names the old entry points raise them with - `entityTypeID` for the
	 * owner type and `entityID` for the identifier - and the refusal of the identifier keeps its message as
	 * well. {@see ArgumentException::getParameter()} is observable behaviour, the consumers of the old
	 * address class live outside the module, and the sibling methods of that class still answer in exactly
	 * these words.
	 *
	 * @throws ArgumentOutOfRangeException When the owner type is not a CRM entity type.
	 * @throws ArgumentException When the owner identifier is not positive.
	 */
	public static function assertOwnerIsDefined(int $ownerTypeId, int $ownerId): void
	{
		if (!CCrmOwnerType::IsDefined($ownerTypeId))
		{
			throw new ArgumentOutOfRangeException(
				'entityTypeID',
				CCrmOwnerType::FirstOwnerType,
				CCrmOwnerType::LastOwnerType,
			);
		}

		if ($ownerId <= 0)
		{
			throw new ArgumentException('Must be greater than zero', 'entityID');
		}
	}

	/**
	 * The write of the old address class: it merges by the unique key instead of inserting, so a row of
	 * the same type is overwritten rather than reported as a conflict, and an empty address blanks an
	 * existing row instead of being refused. The address type is not checked against the dictionary here -
	 * the old entry point never checked it and must not start failing on data it accepts today. The country
	 * code is the other way round: the old entry point does check it and silently drops a code that does
	 * not hold up against the country it came with, so {@see CountryCodeConsistency::clearInconsistent()}
	 * keeps doing exactly that.
	 *
	 * An empty address of an owner that has no row of this type yet writes nothing at all and creates no
	 * requisite; the address of the location module that came with it is released instead of being
	 * linked, and the caller learns that nothing was written from `self::IS_WRITTEN`.
	 *
	 * @param array<string, mixed> $fields Address fields, {@see self::ADDRESS_FIELDS} plus `LOC_ADDR_ID`
	 *        and {@see self::ROW_SHAPE_FIELDS}.
	 * @return Result Data of a successful result:
	 *         `[self::IS_WRITTEN => bool, self::REQUISITE_ID => int]`. The requisite is zero when nothing
	 *         was written and when the row does not live on a requisite at all.
	 * @throws ArgumentOutOfRangeException When the owner type is not a CRM entity type.
	 * @throws ArgumentException When the owner identifier is not positive.
	 */
	public function writeWithoutPermissionCheck(
		int $ownerTypeId,
		int $ownerId,
		int $typeId,
		array $fields,
	): Result
	{
		self::assertOwnerIsDefined($ownerTypeId, $ownerId);

		// A bare link is an address too, and the fields behind it decide whether this write has anything
		// to store at all.
		$addressFields = array_merge(
			$this->locationLink->exportFields(self::extractAddressFields($fields)),
			self::extractRowShapeFields($fields),
		);
		$previousRow = $this->findPreviousRow($ownerTypeId, $ownerId, $typeId);

		if ($previousRow === null && EntityAddress::isEmpty($addressFields))
		{
			$this->locationLink->release(
				(int)($addressFields['LOC_ADDR_ID'] ?? 0),
				$typeId,
				$ownerTypeId,
				$ownerId,
			);

			return (new Result())->setData([self::IS_WRITTEN => false, self::REQUISITE_ID => 0]);
		}

		$writeResult = $this->writeAddress(
			$ownerTypeId,
			$ownerId,
			$typeId,
			CountryCodeConsistency::clearInconsistent($addressFields),
			overwriteOccupiedType: true,
			previousLocationAddressId: (int)($previousRow['LOC_ADDR_ID'] ?? 0),
			previousIsDefault: (bool)($previousRow['IS_DEF'] ?? false),
		);
		if (!$writeResult->isSuccess())
		{
			return $writeResult;
		}

		$writtenData = $writeResult->getData();
		$this->effects->runAfterWrite($ownerTypeId, $ownerId, $writtenData[self::STORED_FIELDS]);

		return (new Result())->setData([
			self::IS_WRITTEN => true,
			self::REQUISITE_ID => $writtenData[self::REQUISITE_ID],
		]);
	}

	/**
	 * Makes the addresses of one record the default ones among the records of the same owner and publishes
	 * the event of the old entry point. With a zero address type the flag is recalculated for every type
	 * at once, which is what the reset of the default requisite relies on.
	 *
	 * The event is published whatever the storage did: the old entry point never asked.
	 *
	 * A write of an address reaches this operation on its way through the requisite it lands on.
	 */
	public function setDefaultFlagWithoutPermissionCheck(int $entityTypeId, int $entityId, int $typeId = 0): void
	{
		$this->applyDefaultFlag($typeId, $entityTypeId, $entityId);

		$this->effects->publishDefaultFlagSet([
			'TYPE_ID' => $typeId,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
		]);
	}

	// endregion

	/**
	 * The write itself: resolve where the row lives, store it, and - when it is the default address of its
	 * type - take the flag away from the other records of the same owner and, when the placement asks for
	 * it, make the requisite the default one of the owner.
	 *
	 * The order matters. The flag is cleared after the row exists, otherwise the row of the very type
	 * being written would keep the flag of its predecessor, and the default requisite is reset last: that
	 * reset recalculates the flag for every address type of the owner and has to see the new row.
	 *
	 * The geodata of the address is settled before the row is built - the row stores the identifier of the
	 * address of the location module. Like the legacy write, these storage operations are independent.
	 *
	 * @param array<string, mixed> $addressFields
	 * @param bool $overwriteOccupiedType `true` merges into a row of the same type already living on the
	 *        record, `false` reports it as a domain conflict.
	 * @param int $previousLocationAddressId Address of the location module the owner keeps for this
	 *        address type today, 0 when there is none.
	 * @param bool $previousIsDefault Whether the row of this type the owner already keeps is the default
	 *        one of its type.
	 * @return Result Data of a successful result:
	 *         `[self::ADDRESS_ID => int, self::REQUISITE_ID => int, self::STORED_FIELDS => array]`.
	 */
	private function writeAddress(
		int $ownerTypeId,
		int $ownerId,
		int $typeId,
		array $addressFields,
		bool $overwriteOccupiedType,
		int $previousLocationAddressId,
		bool $previousIsDefault,
	): Result
	{
		$resolution = $this->resolvePlacement($ownerTypeId, $ownerId, $typeId);
		if (!$resolution->isSuccess())
		{
			return (new Result())->addErrors($resolution->getErrors());
		}

		/** @var AddressPlacement $placement */
		$placement = $resolution->getData()[self::PLACEMENT];
		$addressFields = $this->locationLink->bindToRecord(
			$placement,
			$addressFields,
			$previousLocationAddressId,
			self::extractLanguageId($addressFields),
		);

		[$anchorTypeId, $anchorId] = self::resolveAnchor($ownerTypeId, $ownerId, $addressFields);
		$isDefault = self::resolveDefaultFlag($ownerTypeId, $addressFields, $previousIsDefault);

		$storedFields = self::buildRow(
			$placement->typeId,
			$placement->recordTypeId,
			$placement->recordId,
			$anchorTypeId,
			$anchorId,
			$addressFields,
			isDefault: $isDefault,
		);

		$storeResult = $this->storeRow($storedFields, $overwriteOccupiedType);
		if (!$storeResult->isSuccess())
		{
			return $storeResult;
		}

		if ($isDefault)
		{
			$this->applyDefaultFlag($placement->typeId, $placement->recordTypeId, $placement->recordId);
		}

		if ($resolution->getData()[self::RESET_DEFAULT_REQUISITE])
		{
			$this->resetDefaultRequisite($ownerTypeId, $ownerId, $placement->recordId);
		}

		return (new Result())->setData([
			self::ADDRESS_ID => (int)$storeResult->getData()[self::ADDRESS_ID],
			self::REQUISITE_ID => $placement->recordTypeId === CCrmOwnerType::Requisite ? $placement->recordId : 0,
			self::STORED_FIELDS => $storedFields,
		]);
	}

	/**
	 * The geodata is settled before the row is rewritten. Like {@see self::writeAddress()}, these storage
	 * operations are independent. Nothing about the row is decided anew: the record it lives on, its type,
	 * its anchor and its default flag are the ones it already has.
	 *
	 * @param array<string, mixed> $row Row as the storage keeps it.
	 * @param array<string, mixed> $addressFields
	 * @return Result Data of a successful result: `[self::STORED_FIELDS => array]`.
	 */
	private function rewriteRow(AddressPlacement $placement, array $row, array $addressFields): Result
	{
		$addressFields = $this->locationLink->bindToRecord($placement, $addressFields, $row['LOC_ADDR_ID']);

		$storedFields = self::buildRow(
			$placement->typeId,
			$placement->recordTypeId,
			$placement->recordId,
			$row['ANCHOR_TYPE_ID'],
			$row['ANCHOR_ID'],
			$addressFields,
			isDefault: $row['IS_DEF'],
		);

		$updateResult = $this->storage->updateRow(
			$placement->typeId,
			$placement->recordTypeId,
			$placement->recordId,
			$storedFields,
		);

		return $updateResult->isSuccess()
			? (new Result())->setData([self::STORED_FIELDS => $storedFields])
			: $updateResult;
	}

	/**
	 * Where a stored row lives, read from the row itself: the record of the row is what the geodata of the
	 * row is linked to, and taking it from the owner of the route instead would name a record of somebody
	 * else. `null` means the row has a shape an owner of this type could not have written - a contact or a
	 * company keeps its addresses on one of its requisites, every other owner on itself - and a row of such
	 * a shape is no row of this owner to change.
	 *
	 * @param array<string, mixed> $row Row as the storage keeps it.
	 */
	private static function resolvePlacementOfStoredRow(array $row, int $ownerTypeId, int $ownerId): ?AddressPlacement
	{
		$typeId = (int)$row['TYPE_ID'];
		$recordTypeId = (int)$row['ENTITY_TYPE_ID'];
		$recordId = (int)$row['ENTITY_ID'];

		if (AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			return $recordTypeId === CCrmOwnerType::Requisite
				? AddressPlacement::onRequisiteOfOwner($typeId, $ownerTypeId, $ownerId, $recordId)
				: null;
		}

		return $recordTypeId === $ownerTypeId && $recordId === $ownerId
			? AddressPlacement::onOwnerItself($typeId, $ownerTypeId, $ownerId)
			: null;
	}

	/**
	 * Whether a change leaves the row exactly as it stands: every address field is asked to hold the value
	 * it already holds and the geodata behind the row is the one it already refers to. A row that is
	 * already what it is asked to be is neither written nor announced.
	 *
	 * @param array<string, mixed> $addressFields
	 * @param array<string, mixed> $storedAddressFields
	 */
	private static function isSameAddress(array $addressFields, array $storedAddressFields): bool
	{
		foreach (self::ADDRESS_FIELDS as $field)
		{
			if ((string)($addressFields[$field] ?? '') !== (string)($storedAddressFields[$field] ?? ''))
			{
				return false;
			}
		}

		return (int)($addressFields['LOC_ADDR_ID'] ?? 0) === (int)($storedAddressFields['LOC_ADDR_ID'] ?? 0);
	}

	/**
	 * Where the row of this owner is stored, and whether the owner has to be told that the requisite the
	 * row landed on is now its default one.
	 *
	 * A contact and a company keep the address on one of their requisites, and choosing that requisite is
	 * a scenario of its own - {@see RequisiteResolver}. Every other owner keeps the row on itself, so
	 * there is nothing to choose, nothing to create and no default requisite to move.
	 *
	 * @return Result Data of a successful result:
	 *         `[self::PLACEMENT => AddressPlacement, self::RESET_DEFAULT_REQUISITE => bool]`. An
	 *         unsuccessful result means the owner had no requisite at all and a new one could not be
	 *         created.
	 */
	private function resolvePlacement(int $ownerTypeId, int $ownerId, int $typeId): Result
	{
		if (!AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			return (new Result())->setData([
				self::PLACEMENT => AddressPlacement::onOwnerItself($typeId, $ownerTypeId, $ownerId),
				self::RESET_DEFAULT_REQUISITE => false,
			]);
		}

		$resolution = $this->requisiteResolver->resolve($ownerTypeId, $ownerId, $typeId);
		if (!$resolution->isSuccess())
		{
			return (new Result())->addErrors($resolution->getErrors());
		}

		return (new Result())->setData([
			self::PLACEMENT => AddressPlacement::onRequisiteOfOwner(
				$typeId,
				$ownerTypeId,
				$ownerId,
				(int)$resolution->getData()[RequisiteResolver::REQUISITE_ID],
			),
			self::RESET_DEFAULT_REQUISITE => (bool)$resolution->getData()[RequisiteResolver::RESET_DEFAULT_REQUISITE],
		]);
	}

	/**
	 * The row of this address type the owner already keeps, `null` when there is none.
	 *
	 * Where to look is the same choice again. A row of a contact or a company lives on one of the
	 * requisites of the owner and is found through the anchor columns and the default flag: which
	 * requisite that is decides where the write lands. A row of an owner that keeps its addresses on
	 * itself is found by its key alone - neither an anchor naming another entity nor a cleared default
	 * flag hides it from the write, which is why an empty address of such an owner blanks a row a contact
	 * would have called non-existent.
	 *
	 * The read applies no permission restriction: the write path has to get the same answer for an agent,
	 * an import and a call made without a user.
	 *
	 * @return array<string, mixed>|null Row as {@see AddressRepository::findByKey()} returns it.
	 */
	private function findPreviousRow(int $ownerTypeId, int $ownerId, int $typeId): ?array
	{
		if (!AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			return $this->storage->findRow(new AddressIdentifier($ownerTypeId, $ownerId, $typeId));
		}

		$requisiteId = $this->storage->findRequisiteOfDefaultAddress(
			$ownerTypeId,
			$ownerId,
			$typeId,
		);

		return $requisiteId > 0
			? $this->storage->findRow(new AddressIdentifier(CCrmOwnerType::Requisite, $requisiteId, $typeId))
			: null;
	}

	/**
	 * The anchor columns of the row: the CRM owner the address belongs to.
	 *
	 * An owner that keeps the address on one of its requisites is always the anchor of its own row - the
	 * row names the requisite, and the anchor is the only place the owner is left. An owner that keeps the
	 * row on itself takes the anchor from the input, and each of the two columns falls back to the owner
	 * on its own: an anchor naming another entity in one column and the owner in the other is a reachable
	 * result and the one the old REST of a requisite address produces today.
	 *
	 * @param array<string, mixed> $addressFields
	 * @return array{0: int, 1: int} Anchor type and anchor.
	 */
	private static function resolveAnchor(int $ownerTypeId, int $ownerId, array $addressFields): array
	{
		if (AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			return [$ownerTypeId, $ownerId];
		}

		$anchorTypeId = (int)($addressFields['ANCHOR_TYPE_ID'] ?? 0);
		$anchorId = (int)($addressFields['ANCHOR_ID'] ?? 0);

		return [
			CCrmOwnerType::IsDefined($anchorTypeId) ? $anchorTypeId : $ownerTypeId,
			$anchorId > 0 ? $anchorId : $ownerId,
		];
	}

	/**
	 * Whether the row is the default address of its type among the records of its owner.
	 *
	 * An address kept on a requisite of its owner is always the default one of its type: the write lands
	 * on the very requisite that holds it. An owner that keeps the row on itself inherits the flag of the
	 * row that is already there, and only a requisite can raise it from the input - by one of exactly
	 * three values, {@see self::DEFAULT_FLAG_INPUT_VALUES}. Anything else leaves the flag down: the old
	 * entry point compares the value instead of interpreting it, and normalising it here would raise the
	 * flag where it stays down today.
	 *
	 * @param array<string, mixed> $addressFields
	 */
	private static function resolveDefaultFlag(
		int $ownerTypeId,
		array $addressFields,
		bool $previousIsDefault,
	): bool
	{
		if (AddressPlacement::isKeptOnARequisite($ownerTypeId) || $previousIsDefault)
		{
			return true;
		}

		return $ownerTypeId === CCrmOwnerType::Requisite
			&& in_array($addressFields['IS_DEF'] ?? null, self::DEFAULT_FLAG_INPUT_VALUES, true);
	}

	/**
	 * The two ways the row reaches the storage, and which one is taken is the difference between the two
	 * lines of the class.
	 *
	 * A write that overwrites a taken type merges by the unique key in one statement: the answer to
	 * "is this type taken" is the same either way, and the merge is what the old write path did. A write
	 * that reports a taken type inserts and lets the unique key of the storage raise the conflict - an
	 * ask-then-write pair would answer the same question with a window in between.
	 *
	 * @param array<string, mixed> $storedFields
	 * @return Result Data of a successful result: `[self::ADDRESS_ID => int]`. The merging line answers
	 *         with a zero: the statement names no identifier, and none of its callers asks for one.
	 */
	private function storeRow(array $storedFields, bool $overwriteOccupiedType): Result
	{
		if ($overwriteOccupiedType)
		{
			$this->storage->mergeRow($storedFields);

			return (new Result())->setData([self::ADDRESS_ID => 0]);
		}

		try
		{
			$addResult = $this->storage->addRow($storedFields);

			return $addResult->isSuccess()
				? (new Result())->setData([self::ADDRESS_ID => (int)$addResult->getData()['ID']])
				: (new Result())->addErrors($addResult->getErrors());
		}
		catch (DuplicateEntryException)
		{
			return (new Result())->addError(new Error(
				'The requisite already holds an address of this type.',
				self::ERROR_ADDRESS_TYPE_OCCUPIED,
			));
		}
	}

	/**
	 * The default flag among the addresses of one record owner: the rows of the given one keep it, the
	 * rows of its siblings lose it.
	 *
	 * The alternative entry point of the module does the same and publishes an event on top, which the
	 * write path must not publish a second time - hence the storage operation and not that entry point.
	 */
	private function applyDefaultFlag(int $typeId, int $entityTypeId, int $entityId): void
	{
		$this->storage->setDefaultAmongOwnerRecords($typeId, $entityTypeId, $entityId);
	}

	/**
	 * Makes the requisite the default one of the owner. The zero of the bank detail is passed on purpose:
	 * the owner loses its selected bank detail exactly as it does today.
	 */
	private function resetDefaultRequisite(int $ownerTypeId, int $ownerId, int $requisiteId): void
	{
		EntityRequisite::setDef($ownerTypeId, $ownerId, $requisiteId, 0, true);
	}

	/**
	 * The caller may leave the identifier of the owner out, so the owner to go on with is the anchor of the
	 * row that comes back and not the argument - the two are the same whenever the argument named one.
	 *
	 * @return array<string, mixed> Row of the address to be changed or deleted.
	 * @throws ObjectNotFoundException
	 */
	private function requireAddressToWrite(int $addressId, int $ownerTypeId, int $ownerId, ?int $userId): array
	{
		$row = $this->storage->findRowById($addressId);
		if ($row === null || !$this->getPermissions($userId)->canWriteAddress($row, $ownerTypeId, $ownerId))
		{
			throw new ObjectNotFoundException("Address not found: {$addressId}");
		}

		return $row;
	}

	/**
	 * @throws ObjectNotFoundException When the owner does not exist or this user may not read it,
	 *         {@see self::describeOwner()}.
	 */
	private function assertCanReadOwner(int $ownerTypeId, int $ownerId, ?int $userId): void
	{
		if (!$this->getPermissions($userId)->canReadOwner($ownerTypeId, $ownerId))
		{
			throw new ObjectNotFoundException(self::describeOwner($ownerTypeId, $ownerId));
		}
	}

	/**
	 * @throws ObjectNotFoundException When the owner does not exist or this user may not change it,
	 *         {@see self::describeOwner()}.
	 */
	private function assertCanWriteOwner(int $ownerTypeId, int $ownerId, ?int $userId): void
	{
		if (!$this->getPermissions($userId)->canWriteOwner($ownerTypeId, $ownerId))
		{
			throw new ObjectNotFoundException(self::describeOwner($ownerTypeId, $ownerId));
		}
	}

	private function getPermissions(?int $userId): OwnerAddressPermissions
	{
		return new OwnerAddressPermissions($userId);
	}

	/**
	 * The row as the storage keeps it: the record owns it, the CRM owner sits in the anchor columns.
	 *
	 * An address field that came empty stays an empty string here. The row is also the payload of the write
	 * event, and the old entry point published it with the empty strings it had built, turning them into no
	 * value at all one level lower - so that conversion stays where the row is stored,
	 * {@see AddressRepository}, and every subscriber keeps seeing what it sees today.
	 *
	 * @param array<string, mixed> $addressFields
	 * @return array<string, mixed>
	 */
	private static function buildRow(
		int $typeId,
		int $entityTypeId,
		int $entityId,
		int $anchorTypeId,
		int $anchorId,
		array $addressFields,
		bool $isDefault,
	): array
	{
		$row = [
			'TYPE_ID' => $typeId,
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
			'ANCHOR_TYPE_ID' => $anchorTypeId,
			'ANCHOR_ID' => $anchorId,
			'LOC_ADDR_ID' => (int)($addressFields['LOC_ADDR_ID'] ?? 0),
			'IS_DEF' => $isDefault,
		];

		foreach (self::ADDRESS_FIELDS as $field)
		{
			$row[$field] = (string)($addressFields[$field] ?? '');
		}

		return $row;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, int>
	 */
	private static function buildAddressKey(array $row): array
	{
		return [
			'ENTITY_TYPE_ID' => (int)$row['ENTITY_TYPE_ID'],
			'ENTITY_ID' => (int)$row['ENTITY_ID'],
			'TYPE_ID' => (int)$row['TYPE_ID'],
		];
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function extractAddressFields(array $fields): array
	{
		return array_intersect_key($fields, array_flip([...self::ADDRESS_FIELDS, 'LOC_ADDR_ID']));
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array<string, mixed>
	 */
	private static function extractRowShapeFields(array $fields): array
	{
		return array_intersect_key($fields, array_flip(self::ROW_SHAPE_FIELDS));
	}

	/**
	 * The language a newly created address of the location module is written in, `null` when the input
	 * names none: a code of exactly two characters is taken as it is, and anything else is not a language.
	 *
	 * @param array<string, mixed> $addressFields
	 */
	private static function extractLanguageId(array $addressFields): ?string
	{
		$languageId = $addressFields['LANGUAGE_ID'] ?? null;

		return is_string($languageId) && mb_strlen($languageId) === 2 ? $languageId : null;
	}

	private static function countryCodeMismatchResult(): Result
	{
		return (new Result())->addError(new Error(
			CountryCodeConsistency::MISMATCH_MESSAGE,
			self::ERROR_ADDRESS_COUNTRY_CODE_MISMATCH,
		));
	}

	private static function emptyAddressResult(): Result
	{
		return (new Result())->addError(
			new Error('The address has no value in any of its fields.', self::ERROR_ADDRESS_IS_EMPTY),
		);
	}

	/**
	 * The refusal of an owner, worded neutrally on purpose: one exception answers both an owner that is not
	 * there and an owner this user may not touch, and the two are deliberately alike to the caller. The text
	 * is read in logs and in test output, so it names the outcome - the owner is not available to this call -
	 * and not which of the two reasons produced it.
	 */
	private static function describeOwner(int $ownerTypeId, int $ownerId): string
	{
		return CCrmOwnerType::ResolveName($ownerTypeId) . " not available: {$ownerId}";
	}
}
