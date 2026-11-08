<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite as InternalRequisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteAddress as InternalRequisiteAddress;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteBankDetail as InternalRequisiteBankDetail;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteRepository;
use Bitrix\Crm\V2\Internal\Service\Requisite\OwnerResolver;
use Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteRelationLoader;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteAddress;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteBankDetail;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Crm\V2\Public\Provider\Requisite\Exception\NotFoundException;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\ItemId;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;

/**
 * Read-side public API for the requisites of a CRM contact or company.
 *
 * A requisite carries no permissions of its own - the right to read one is the right to read its
 * **owner** - so every read of this provider resolves the owner first and refuses before it answers
 * anything about the record. The access user is fixed by the constructor and is always explicit: there is
 * no mode in which the right is resolved through the global current user, and no way to switch the check
 * off.
 *
 * ### One absence, three causes
 *
 * A record that is not there, a record this user may not read, and a record of an owner other than the
 * one the caller addressed are one and the same answer: {@see NotFoundException}. A read that told them
 * apart would let a client learn by trying which requisites and which counterparties a portal keeps. The
 * page of an owner is refused the same way and for the same three causes - an owner that is not there is
 * not an owner without requisites, and the two must not be told apart either.
 *
 * The type of the owner is an argument rather than something read out of the record: it is the invariant
 * of the caller - a route of the contact answers for contacts alone - and checking the record against it
 * is what makes a requisite of another owner type absent rather than readable through the wrong door.
 *
 * ### What a read carries
 *
 * The field set is the caller's ({@see Requisite} field names, an empty list meaning all of them), and a
 * field left out of it is not carried by the answer at all: {@see Requisite::hasField()} tells it from a
 * cell that is empty, and only because the provider never fills what was not asked for. The identifier is
 * the one exception - the read is keyed by it and always answers it.
 *
 * The relations - the addresses and the bank details - are read only when they are asked for, for the
 * whole page at once, and a requisite that has none of a relation that was asked for answers an empty
 * collection rather than nothing.
 *
 * Example:
 * ```php
 * $requisite = (new RequisiteProvider($userId))->getById(
 *     OwnerType::COMPANY,
 *     $id,
 *     [Requisite::name, Requisite::inn],
 *     [Requisite::addresses],
 * );
 * ```
 */
final class RequisiteProvider
{
	private readonly RequisiteRepository $requisites;

	private readonly RequisitePermissions $permissions;

	private readonly OwnerResolver $owners;

	private readonly RequisiteRelationLoader $relations;

	public function __construct(private readonly int $userId)
	{
		$this->requisites = new RequisiteRepository();
		$this->permissions = new RequisitePermissions();
		$this->owners = new OwnerResolver();
		$this->relations = new RequisiteRelationLoader();
	}

	/**
	 * A single requisite of an owner of $ownerTypeId.
	 *
	 * ### The owner is asked about by permission and not by existence
	 *
	 * A requisite whose owner has been erased is a row this read still answers, and it answers it to
	 * whoever the permission model lets reach owners of that type at all: a CRM administrator, whose
	 * answer never looks at the owner, and a user holding the type-wide right, whose answer does not
	 * depend on what the owner carries. To everyone else the same row is an absence, because a narrower
	 * right has no attributes of that owner left to match. Pre-reform reading answered the same way, and
	 * by an even shorter route: `EntityRequisite::getById()` asked nothing whatever, and `checkReadPermission()`
	 * came down to that same right over the owner without ever asking whether the owner was there -
	 * observed on the master revision of the class against a contact erased past the recycle bin.
	 * Checking existence here would narrow the read past legacy for the one class of users that could
	 * already read every owner of the type, and would cost a second read of the owner on every read by
	 * identifier - one the permission of an administrator never needs.
	 *
	 * That such a row exists at all is the doing of the delete of the owner rather than of this read. A
	 * delete to the recycle bin carries the row over to the suspended owner type
	 * ({@see \Bitrix\Crm\Recycling\RequisiteControllerMixin::suspendRequisites()}), where the check of
	 * the owner type below already turns it into an absence; a delete past the bin leaves the row behind
	 * until the cleaning of the owner runs, and that cleaning is queued rather than immediate by default
	 * ({@see \Bitrix\Crm\Cleaning\CleaningManager}). **Ask the question again if that changes**: an
	 * orphan meant to outlive its cleaning, or a rule that requisites go with their owner synchronously,
	 * each makes a different answer of the paragraph above.
	 *
	 * ### Hence the asymmetry with {@see self::getListByOwner()}, which does ask
	 *
	 * The two reads address different objects. This one addresses a requisite, and the requisite is
	 * there. That one addresses an owner, and an owner that is not there has to be refused, or it would
	 * be indistinguishable from an owner that keeps no requisites - the one thing the group does promise
	 * to tell apart. So the asymmetry is the contract and not an oversight: do not restore the symmetry
	 * by adding {@see OwnerResolver::exists()} here without settling that first.
	 *
	 * @param string[] $select the {@see Requisite} fields to answer; an empty list means every field of a
	 *        requisite. The identifier is answered whether it is listed or not.
	 * @param string[] $relations {@see Requisite::addresses} and {@see Requisite::bankRequisites}; a
	 *        relation left out is not read and is not answered for.
	 * @throws NotFoundException the requisite is not there, this user may not read it, or it belongs to
	 *         an owner of another type - one answer for the three of them.
	 * @throws ArgumentException a field or a relation that is none of a requisite.
	 * @throws SystemException a failure of the storage.
	 */
	public function getById(int $ownerTypeId, int $id, array $select = [], array $relations = []): Requisite
	{
		$requisite = $this->requisites->findById($id, self::selectWithOwner($select));
		if ($requisite === null || $requisite->ownerTypeId !== $ownerTypeId || $requisite->ownerId === null)
		{
			throw new NotFoundException(RequisiteError::REQUISITE_NOT_FOUND);
		}

		if (!$this->permissions->canRead($ownerTypeId, $requisite->ownerId, $this->userId))
		{
			throw new NotFoundException(RequisiteError::REQUISITE_NOT_FOUND);
		}

		return $this->toPublicList([$requisite], $select, $relations)[0];
	}

	/** @return Result<ItemId> */
	public function resolveRequisiteOwner(int $requisiteId, int $userId): Result
	{
		$result = new Result();
		$requisite = $this->requisites->findById(
			$requisiteId,
			[Requisite::ownerTypeId, Requisite::ownerId],
		);
		if (
			$requisite === null
			|| $requisite->ownerId === null
			|| !$this->permissions->canRead($requisite->ownerTypeId, $requisite->ownerId, $userId)
		)
		{
			return $result->addError(new Error('The requisite was not found.', RequisiteError::REQUISITE_NOT_FOUND));
		}

		return $result->setData([
			'owner' => new ItemId(EntityType::fromId($requisite->ownerTypeId), $requisite->ownerId),
		]);
	}

	/**
	 * A page of the requisites of one owner, ordered as asked.
	 *
	 * An owner the user may read and that keeps no requisites answers an empty page; an owner that is not
	 * there, or that this user may not read, or of a type no requisite belongs to, is refused instead.
	 *
	 * @param array<string, string> $order {@see Requisite} field name => `ASC`/`DESC`. The identifier
	 *        settles whatever the ordering leaves equal, so the pages of one read do not overlap.
	 * @param string[] $select the {@see Requisite} fields to answer; an empty list means every field.
	 * @param string[] $relations {@see Requisite::addresses} and {@see Requisite::bankRequisites}, read
	 *        for the whole page at once.
	 * @return Requisite[]
	 * @throws NotFoundException the owner is not there, this user may not read it, or no requisite
	 *         belongs to an owner of its type - one answer for the three of them.
	 * @throws ArgumentException a field, an ordering or a relation that is none of a requisite.
	 * @throws SystemException a failure of the storage.
	 */
	public function getListByOwner(
		int $ownerTypeId,
		int $ownerId,
		array $order = [],
		?int $limit = null,
		int $offset = 0,
		array $select = [],
		array $relations = [],
	): array
	{
		$this->requireReadableOwner($ownerTypeId, $ownerId);

		$requisites = $this->requisites->findByOwner(
			$ownerTypeId,
			$ownerId,
			$order,
			$limit,
			$offset,
			self::selectWithOwner($select),
		);

		return $this->toPublicList($requisites, $select, $relations);
	}

	/**
	 * Three questions in the order they have to be asked, all three answered the same way: a type no
	 * requisite belongs to, a user who may not reach this owner, an owner that is not there. The order is
	 * not about the answer - it is about not asking a permission over a type the model has no permission
	 * for, and not reading a row before the user is allowed to know of it. It is the order the write of
	 * the domain asks them in.
	 *
	 * @throws NotFoundException
	 */
	private function requireReadableOwner(int $ownerTypeId, int $ownerId): void
	{
		$readable =
			$this->owners->isSupportedType($ownerTypeId)
			&& $this->permissions->canRead($ownerTypeId, $ownerId, $this->userId)
			&& $this->owners->exists($ownerTypeId, $ownerId)
		;

		if (!$readable)
		{
			throw new NotFoundException(RequisiteError::OWNER_NOT_FOUND);
		}
	}

	/**
	 * @param InternalRequisite[] $requisites
	 * @param string[] $select
	 * @param string[] $relations
	 * @return Requisite[]
	 * @throws ArgumentException
	 * @throws SystemException
	 */
	private function toPublicList(array $requisites, array $select, array $relations): array
	{
		$fields = self::fieldsToAnswer($select);
		$loaded = $relations === [] ? [] : $this->relations->load($requisites, $relations);

		$models = [];
		foreach ($requisites as $requisite)
		{
			$model = new Requisite();
			foreach ($fields as $field)
			{
				// The field names of a requisite are the property names of the internal record by
				// contract, so a record becomes a model by name rather than by a third list of fields.
				$model->internalSet($field, $requisite->$field);
			}

			// The outer key is asked about by existence rather than by `isset()`: what the loader answers
			// is the relations that were asked for, and one of them holding an empty array for every
			// requisite of the page is not the same thing as it not having been asked for.
			if (array_key_exists(RequisiteRelationLoader::RELATION_ADDRESSES, $loaded))
			{
				$model->setAddresses(...array_map(
					self::toPublicAddress(...),
					$loaded[RequisiteRelationLoader::RELATION_ADDRESSES][$requisite->id],
				));
			}

			if (array_key_exists(RequisiteRelationLoader::RELATION_BANK_REQUISITES, $loaded))
			{
				$model->setBankRequisites(...array_map(
					self::toPublicBankDetail(...),
					$loaded[RequisiteRelationLoader::RELATION_BANK_REQUISITES][$requisite->id],
				));
			}

			$models[] = $model;
		}

		return $models;
	}

	/**
	 * The fields to read: the ones the caller asked for, and the owner whether it asked for it or not -
	 * the right to read the record is the right to read that owner, and it cannot be checked without it.
	 * The owner is read rather than answered: {@see self::fieldsToAnswer()} decides what the model
	 * carries.
	 *
	 * @param string[] $select
	 * @return string[]
	 */
	private static function selectWithOwner(array $select): array
	{
		if ($select === [])
		{
			return [];
		}

		return array_values(array_unique([...$select, Requisite::ownerId, Requisite::ownerTypeId]));
	}

	/**
	 * The fields the answer carries: the ones the caller named, plus the identifier the read is keyed by.
	 * A field outside this list is never set on the model, and that is the only thing that keeps "was not
	 * asked for" apart from "the cell is empty".
	 *
	 * @param string[] $select
	 * @return string[]
	 */
	private static function fieldsToAnswer(array $select): array
	{
		if ($select === [])
		{
			return array_keys(RequisiteColumnMap::COLUMN_BY_FIELD);
		}

		return array_values(array_unique([Requisite::id, ...$select]));
	}

	private static function toPublicAddress(InternalRequisiteAddress $address): RequisiteAddress
	{
		// The published address is the internal one without the identifier of the requisite it hangs off -
		// which the requisite it is answered on already names - so it is built by name rather than by
		// nine positional values kept in step with a constructor.
		$values = get_object_vars($address);
		unset($values['requisiteId']);

		return new RequisiteAddress(...$values);
	}

	private static function toPublicBankDetail(InternalRequisiteBankDetail $bankDetail): RequisiteBankDetail
	{
		/** @see self::toPublicAddress() for why the values travel by name. */
		$values = get_object_vars($bankDetail);
		unset($values['requisiteId']);

		return new RequisiteBankDetail(...$values);
	}
}
