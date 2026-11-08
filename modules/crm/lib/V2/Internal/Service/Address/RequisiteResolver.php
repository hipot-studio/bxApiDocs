<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Address;

use Bitrix\Crm\EntityRequisite;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Crm\V2\Internal\Entity\Address\AddressPlacement;
use Bitrix\Crm\V2\Internal\Repository\Address\AddressRepository;
use Bitrix\Crm\V2\Public\Command\Address\OwnerAddressError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * Answers the one question the write path of a contact or company address starts with: which requisite
 * does the row belong to.
 *
 * Such an address is never stored on the contact or the company itself. It is stored on one of its
 * requisites, while the contact or the company stays in the anchor columns of the row. Four stages are
 * tried in a fixed order and the first one that names a requisite wins; the order is what decides where
 * the address ends up:
 *
 * 1. the requisite that already holds the default address of the same type;
 * 2. the requisite stored as the selected one in the settings of the owner, when it is a requisite of
 *    this owner;
 * 3. the first requisite of the owner by sort;
 * 4. a freshly created "address only" requisite.
 *
 * The first two stages land on a requisite the owner already associates with addresses, so nothing else
 * has to happen. The last two take a requisite that had nothing to do with addresses, and the owner has
 * to be told that this requisite is now its default one. The resolver reports that as the second half of
 * its answer instead of doing it itself: the reset belongs to the write operation, which owns the order
 * of the storage changes and their effects.
 *
 * @internal
 */
final class RequisiteResolver
{
	/** Identifier of the resolved requisite, int. */
	public const REQUISITE_ID = 'requisiteId';

	/** Whether the caller has to make the resolved requisite the default one of the owner, bool. */
	public const RESET_DEFAULT_REQUISITE = 'resetDefaultRequisite';

	/**
	 * Custom data of {@see OwnerAddressError::ADDRESS_ONLY_REQUISITE_NOT_CREATED}: what the requisite
	 * domain answered, as a list of messages. The message of the error itself says nothing of it,
	 * {@see self::requisiteNotCreatedMessage()}.
	 */
	public const REFUSAL_OF_THE_REQUISITE_DOMAIN = 'refusalOfTheRequisiteDomain';

	private const ADDRESS_ONLY_REQUISITE_SORT = 500;

	private readonly AddressStorage $storage;

	public function __construct(?AddressRepository $repository = null)
	{
		$this->storage = new AddressStorage($repository ?? new AddressRepository());
	}

	/**
	 * @param int $typeId Address type, {@see \Bitrix\Crm\EntityAddressType}.
	 * @return Result Data of a successful result:
	 *         `[self::REQUISITE_ID => int, self::RESET_DEFAULT_REQUISITE => bool]`. An unsuccessful
	 *         result means the owner had no requisite at all and a new one could not be created: the
	 *         write operation has to be aborted.
	 * @throws ArgumentException When the owner is neither a contact nor a company - no other owner keeps
	 *         its addresses on a requisite.
	 */
	public function resolve(int $ownerTypeId, int $ownerId, int $typeId): Result
	{
		if (!AddressPlacement::isKeptOnARequisite($ownerTypeId))
		{
			throw new ArgumentException('Only a contact or a company keeps its addresses on a requisite.', 'ownerTypeId');
		}

		if ($ownerId <= 0)
		{
			throw new ArgumentException('Owner identifier must be positive.', 'ownerId');
		}

		$requisiteId = $this->findRequisiteOfDefaultAddress($ownerTypeId, $ownerId, $typeId);
		if ($requisiteId > 0)
		{
			return self::resolved($requisiteId, false);
		}

		$requisiteId = $this->findSelectedRequisite($ownerTypeId, $ownerId);
		if ($requisiteId > 0)
		{
			return self::resolved($requisiteId, false);
		}

		$requisiteId = $this->findFirstRequisiteBySort($ownerTypeId, $ownerId);
		if ($requisiteId > 0)
		{
			return self::resolved($requisiteId, true);
		}

		return $this->createAddressOnlyRequisite($ownerTypeId, $ownerId);
	}

	/**
	 * Stage one: the requisite holding the default address of the same type.
	 *
	 * The read deliberately goes without a permission restriction: the resolver is reached from agents,
	 * from import and from calls made without a user, and a restricted read would answer that no such
	 * requisite exists and move the address onto another one.
	 */
	private function findRequisiteOfDefaultAddress(int $ownerTypeId, int $ownerId, int $typeId): int
	{
		return $this->storage->findRequisiteOfDefaultAddress(
			$ownerTypeId,
			$ownerId,
			$typeId,
		);
	}

	/**
	 * Stage two: the requisite the owner keeps as the selected one.
	 *
	 * The stored choice is answered for by the owner and not only by the storage: it is taken when the
	 * requisite it names belongs to this very owner, and it is dropped as a stale link otherwise. The old
	 * entry point asked whether such a requisite exists at all, and a setting naming a requisite of another
	 * owner is reachable - {@see \Bitrix\Crm\EntityRequisite::setDef()} answers for the existence of the
	 * owner and for the right to change the requisite, each on its own, and never for the two belonging
	 * together. The write would then land on a requisite of somebody else and move the default flags among
	 * their addresses, so the difference from the old behaviour is a deliberate one; an owner whose setting
	 * points elsewhere goes on to the stages below, exactly as an owner whose setting points at a requisite
	 * that is gone.
	 */
	private function findSelectedRequisite(int $ownerTypeId, int $ownerId): int
	{
		$settings = EntityRequisite::getSingleInstance()->loadSettings($ownerTypeId, $ownerId);
		$selectedRequisiteId = (int)($settings['REQUISITE_ID_SELECTED'] ?? 0);
		if ($selectedRequisiteId <= 0)
		{
			return 0;
		}

		$row = RequisiteTable::query()
			->setSelect(['ID'])
			->where('ID', $selectedRequisiteId)
			->where('ENTITY_TYPE_ID', $ownerTypeId)
			->where('ENTITY_ID', $ownerId)
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}

	/**
	 * Stage three: the first requisite of the owner, sort before identifier.
	 */
	private function findFirstRequisiteBySort(int $ownerTypeId, int $ownerId): int
	{
		$row = RequisiteTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE_ID', $ownerTypeId)
			->where('ENTITY_ID', $ownerId)
			->setOrder(['SORT' => 'ASC', 'ID' => 'ASC'])
			->setLimit(1)
			->fetch()
		;

		return $row ? (int)$row['ID'] : 0;
	}

	/**
	 * Stage four: the owner has no requisite at all and gets one carrying nothing but the address.
	 *
	 * Requisites are still owned by the legacy class, creation included: moving them is not part of this
	 * work. The composition below is the one the legacy write path produces.
	 *
	 * A creation the requisite domain refuses is the published refusal of the group,
	 * {@see OwnerAddressError::ADDRESS_ONLY_REQUISITE_NOT_CREATED}: it is the one way an owner is left with
	 * nowhere to keep an address, and the caller can act on it - a preset the portal no longer has, a limit
	 * of the requisite domain. The code is what travels; the message keeps the words of the old entry point
	 * ({@see self::requisiteNotCreatedMessage()}) and the reason the domain gave is carried beside it, in
	 * the custom data of the error, where it takes no observable form away from anybody.
	 *
	 * A creation that reports success and names no requisite is a different thing altogether: nothing about
	 * the call explains it and no caller can do anything with it. It leaves without a published code, which
	 * is how the group says "this is a failure of the portal" - {@see OwnerAddressError}.
	 */
	private function createAddressOnlyRequisite(int $ownerTypeId, int $ownerId): Result
	{
		$addResult = EntityRequisite::getSingleInstance()->add([
			'ENTITY_TYPE_ID' => $ownerTypeId,
			'ENTITY_ID' => $ownerId,
			'PRESET_ID' => EntityRequisite::getDefaultPresetId($ownerTypeId),
			'NAME' => CCrmOwnerType::GetCaption($ownerTypeId, $ownerId, false),
			'SORT' => self::ADDRESS_ONLY_REQUISITE_SORT,
			'ACTIVE' => 'Y',
			'ADDRESS_ONLY' => 'Y',
		]);

		if (!$addResult->isSuccess())
		{
			return (new Result())->addError(new Error(
				self::requisiteNotCreatedMessage($ownerTypeId, $ownerId),
				OwnerAddressError::ADDRESS_ONLY_REQUISITE_NOT_CREATED->value,
				[self::REFUSAL_OF_THE_REQUISITE_DOMAIN => $addResult->getErrorMessages()],
			));
		}

		$requisiteId = (int)$addResult->getId();
		if ($requisiteId <= 0)
		{
			return (new Result())->addError(
				new Error('The requisite of the owner was created without an identifier.'),
			);
		}

		return self::resolved($requisiteId, true);
	}

	/**
	 * The words a write has always been refused with when the owner could get no requisite, kept as they
	 * are - the stray brace included. {@see \Bitrix\Crm\EntityAddress::register()} builds the message of
	 * its exception out of the errors of this result, its consumers live outside the module and have no
	 * test in this repository, so the text they see is observable behaviour and stays as it was.
	 */
	private static function requisiteNotCreatedMessage(int $ownerTypeId, int $ownerId): string
	{
		return 'Cannot create a ' . mb_strtolower(CCrmOwnerType::ResolveName($ownerTypeId))
			. ' details item (ID: ' . $ownerId . '})';
	}

	private static function resolved(int $requisiteId, bool $resetDefaultRequisite): Result
	{
		return (new Result())->setData([
			self::REQUISITE_ID => $requisiteId,
			self::RESET_DEFAULT_REQUISITE => $resetDefaultRequisite,
		]);
	}
}
