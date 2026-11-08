<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Requisite;

use Bitrix\Crm\EntityBankDetail;
use Bitrix\Crm\Requisite\EntityLink;
use Bitrix\Crm\RequisiteAddress;
use Bitrix\Crm\V2\Internal\Access\Requisite\RequisitePermissions;
use Bitrix\Crm\V2\Internal\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteTarget;
use Bitrix\Crm\V2\Internal\Exception\Requisite\RequisiteStorageExceptionMapper;
use Bitrix\Crm\V2\Internal\Exception\Requisite\StorageRefusedException;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteColumnMap;
use Bitrix\Crm\V2\Internal\Repository\Requisite\RequisiteWriteRepository;
use Bitrix\Crm\V2\Public\OwnerType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\DateTime;

/**
 * The three operations that write a requisite, and the one place the rule of writing one lives.
 *
 * Every operation has the same shape. What has to be true before anything is written - the owner, the
 * permissions of the user over it, the preset, the fields against that preset - is decided first, on the
 * row as it stands then. Then the write: the rows that belong to the requisite and the row of the
 * requisite itself. What follows from a write - the duplicate indexes, the tagged cache of the selector,
 * the event of the domain - runs after it and only when it went through ({@see RequisiteEffects}).
 *
 * **Nothing here is wrapped in a transaction, and that is a decision rather than an omission.** A write of
 * a requisite has never been atomic: `\Bitrix\Crm\EntityRequisite` performs the same steps one after the
 * other with nothing around them, and these operations perform them the same way, so that the two lines
 * fail alike. Two consequences follow, and both are the behaviour of legacy rather than of this class.
 *
 * *A deletion is not atomic.* The links of a requisite, its addresses and its bank details are erased
 * before its row ({@see self::eraseWhatBelongsToRequisite()}), so a storage that refuses the row leaves a
 * requisite whose addresses, bank details and links are already gone while the caller is told the deletion
 * failed. Nothing puts them back.
 *
 * *A requisite can change hands between the checks and the write.* The owner is read, and the user asked
 * about it, before anything is written; the write itself goes by the identifier alone and nothing holds
 * the row still in between. `\Bitrix\Crm\EntityRequisite::transferOwnership()` moves every requisite of a
 * record to a suspended owner when that record goes to the recycle bin and back when it is restored, and
 * the compatibility line moves a single one whenever a write names an owner. A move that arrives in that
 * gap lands in a row the operation has already decided it may write, so a change or a deletion can reach a
 * requisite of a record its user was never asked about.
 *
 * **Two entry lines, and the difference between them is the point.** The published operations
 * ({@see self::add()}, {@see self::update()}, {@see self::delete()}) answer for a user: they check what
 * that user may do, validate the fields against the preset of the requisite, write, and run the effects
 * after the write. The compatibility operations ({@see self::addForLegacyFacade()} and its two siblings)
 * answer for `\Bitrix\Crm\EntityRequisite`, which has never checked a permission: they check nothing and
 * run the effects at the moment legacy runs them. That is not a shortcut but the whole reason
 * they exist - the write of a requisite is reached from import, from conversion, from the merge of
 * duplicates and from three other modules, none of which has a user to answer for, and a permission
 * appearing on those paths would break them. The line is closed to new consumers and goes away once the
 * consumers of the write move to the operations that do check.
 *
 * **A refusal of the storage is never read out of a missing exception.** The boundary of the area refuses
 * by throwing ({@see RequisiteWriteRepository}), and so does every step of the cascade of a deletion, the
 * one that answers with a result included ({@see self::eraseWhatBelongsToRequisite()}). So a refusal
 * arrives as an exception, no step after it runs, and the operation answers the single refusal of the
 * storage ({@see RequisiteStorageExceptionMapper}) with whatever of the write had already happened left
 * where it was.
 *
 * **An effect that refuses reaches the caller as an exception, and the write stays.** Nothing guards the
 * effects behind the write: a duplicate index that cannot be rebuilt, a tagged cache that cannot be
 * dropped or a handler of the event of the domain that throws leaves the row written and answers the
 * caller with the exception instead of a {@see Result}. That is what `\Bitrix\Crm\EntityRequisite` has
 * always done - it runs the same effects unguarded and after the write - and keeping the two lines alike
 * here is deliberate: a creation whose effect refused looks to a client like a failed request, so a client
 * that retries it safely creates a second requisite. Undoing the row instead would mean the published
 * operations and the compatibility line answering the same failure differently, which is the one thing this
 * class exists to avoid. The day the effects become undoable it is both lines that change, not this one.
 *
 * @internal
 */
final class RequisiteWriteService
{
	/** The identifier of the requisite a successful creation wrote, in the data of the result. */
	public const DATA_KEY_ID = 'id';

	/**
	 * The requisite is not there - or is there and this user may not have it, or belongs to another
	 * owner than the one the caller addressed. One code for the three of them, because telling them
	 * apart is telling a client about a record it may not see (the invariant of the group).
	 */
	public const ERROR_REQUISITE_NOT_FOUND = 'REQUISITE_NOT_FOUND';

	/**
	 * The same answer about the owner of a requisite being created: absent, hidden by permissions, or of
	 * a type no requisite belongs to.
	 */
	public const ERROR_OWNER_NOT_FOUND = 'OWNER_NOT_FOUND';

	/** The letters the boolean columns keep, and the shape a boolean has in the payload of the event. */
	private const STORED_TRUE = 'Y';
	private const STORED_FALSE = 'N';

	public function __construct(
		private readonly RequisiteWriteRepository $requisites = new RequisiteWriteRepository(),
		private readonly RequisitePermissions $permissions = new RequisitePermissions(),
		private readonly OwnerResolver $owners = new OwnerResolver(),
		private readonly RequisiteFieldValidator $validator = new RequisiteFieldValidator(),
		private readonly RequisiteEffects $effects = new RequisiteEffects(),
		private readonly RequisiteStorageExceptionMapper $refusals = new RequisiteStorageExceptionMapper(),
	)
	{
	}

	/**
	 * Creates a requisite of the owner, on the preset, with the fields the caller named.
	 *
	 * The owner and the preset are arguments rather than fields of the set: the type of the owner is an
	 * invariant of the route and never a value a caller states, and the preset is what the fields are
	 * validated against, so it has to be known before them. A set that names the owner or the preset as
	 * well is overruled by the arguments - which is why the published command states them once, in the
	 * arguments.
	 *
	 * The author and the moment of the creation are written as they are: the user of the request and now.
	 * Legacy writes a zero there because its helper of the current user reads the global without declaring
	 * it, and this is the one declared difference of this operation that is visible from the outside. The
	 * facade line keeps writing whatever it is handed, zero included.
	 *
	 * @return Result the identifier under {@see self::DATA_KEY_ID} on success; on failure the refusals of
	 *         the fields, each naming the field it is about, or the single absence of the owner, or the
	 *         single refusal of the storage.
	 */
	public function add(
		int $ownerTypeId,
		int $ownerId,
		int $presetId,
		RequisiteWriteData $fields,
		int $userId,
	): Result
	{
		$refusal = $this->refuseUnavailableOwner($ownerTypeId, $ownerId, $userId);
		if ($refusal !== null)
		{
			return $refusal;
		}

		$errors = $this->validator->validate($presetId, $fields, true);
		if ($errors !== [])
		{
			return self::failure($errors);
		}

		$written = $fields
			->with(Requisite::FIELD_OWNER_TYPE_ID, $ownerTypeId)
			->with(Requisite::FIELD_OWNER_ID, $ownerId)
			->with(Requisite::FIELD_PRESET_ID, $presetId)
			->with(Requisite::FIELD_CREATED_TIME, new DateTime())
			->with(Requisite::FIELD_CREATED_BY_ID, $userId)
		;

		try
		{
			$id = $this->requisites->insert($written);
		}
		catch (\Throwable $refusal)
		{
			return self::fail($this->refusals->map($refusal, [
				'ownerTypeId' => $ownerTypeId,
				'ownerId' => $ownerId,
				'presetId' => $presetId,
			]));
		}

		$this->effects->afterAdd($ownerTypeId, $ownerId, $id, self::eventFieldsOf($written));

		return (new Result())->setData([self::DATA_KEY_ID => $id]);
	}

	/**
	 * Writes the fields the caller named onto the requisite and leaves the rest of the row alone.
	 *
	 * The preset the fields are validated against is the one the call names, or the one the requisite
	 * names now: changing the preset of an existing requisite is allowed and does not clear the values
	 * outside the new one, which is the behaviour of the card and is a contract of the group.
	 *
	 * A set that names nothing is a successful operation that writes nothing - not even the mark of the
	 * modification, which is what legacy writes and what makes its own "nothing to update" check
	 * unreachable. The checks still run first, so a caller that may not touch the requisite is told so
	 * rather than being answered success.
	 *
	 * The owner is read once, here, and everything after that goes by the identifier alone. A requisite
	 * that changes hands after that read is written all the same, and the class says why that gap is left
	 * open ({@see RequisiteWriteService}).
	 *
	 * $expectedOwnerTypeId is the invariant of the route the call came through, and it is answered here
	 * because the row that answers it is read here anyway: a requisite of another type of owner is the
	 * absence of a requisite, the same answer a missing and an unreachable one get. It is answered before
	 * the preset and the fields on purpose - a refusal naming a field would tell a client about a record of
	 * another type it may not see, which is the one thing the invariant of the group forbids.
	 */
	public function update(int $expectedOwnerTypeId, int $id, RequisiteWriteData $fields, int $userId): Result
	{
		$current = $this->requisites->findWriteTarget($id);
		if ($current === null || $current->ownerTypeId !== $expectedOwnerTypeId)
		{
			return self::absence(self::ERROR_REQUISITE_NOT_FOUND);
		}

		$ownerTypeId = $current->ownerTypeId;
		$ownerId = $current->ownerId;
		if (!$this->permissions->canUpdate($ownerTypeId, $ownerId, $userId))
		{
			return self::absence(self::ERROR_REQUISITE_NOT_FOUND);
		}

		$presetId = $fields->has(Requisite::FIELD_PRESET_ID)
			? (int)$fields->get(Requisite::FIELD_PRESET_ID)
			: $current->presetId
		;

		$errors = $this->validator->validate($presetId, $fields, false);
		if ($errors !== [])
		{
			return self::failure($errors);
		}

		if ($fields->isEmpty())
		{
			return new Result();
		}

		$written = $fields
			->with(Requisite::FIELD_UPDATED_TIME, new DateTime())
			->with(Requisite::FIELD_UPDATED_BY_ID, $userId)
		;

		try
		{
			$this->requisites->update($id, $written);
		}
		catch (\Throwable $refusal)
		{
			return self::fail($this->refusals->map($refusal, [
				'requisiteId' => $id,
				'ownerTypeId' => $ownerTypeId,
				'ownerId' => $ownerId,
				'presetId' => $presetId,
			]));
		}

		// The owner is not writable here, so the write never names one and the effects
		// take the branch that reads it back off the row.
		$this->effects->afterUpdate($id, $ownerTypeId, $ownerId, null, null, self::eventFieldsOf($written));

		return new Result();
	}

	/**
	 * Erases the requisite together with what belongs to it: its place in the registry of the selected
	 * requisites and of the requisite of "my company", its addresses, its bank details, and last the row
	 * itself.
	 *
	 * In that order and with nothing around it, exactly as legacy erases it: a storage that refuses the row
	 * leaves a requisite whose addresses, bank details and links are already gone, and the caller is told
	 * the deletion failed ({@see RequisiteWriteService}).
	 *
	 * An identifier that is not a requisite of this user is the absence of a requisite and nothing happens -
	 * no row is touched and no event goes out. Legacy answers such a call with success and sends the event
	 * anyway, and the facade line keeps doing that. A requisite whose owner is not of $expectedOwnerTypeId
	 * is answered the same absence - the invariant of the route, answered as in {@see self::update()}. A
	 * requisite that changes hands after that read is erased all the same, for the reason the class gives.
	 */
	public function delete(int $expectedOwnerTypeId, int $id, int $userId): Result
	{
		$current = $this->requisites->findWriteTarget($id);
		if ($current === null || $current->ownerTypeId !== $expectedOwnerTypeId)
		{
			return self::absence(self::ERROR_REQUISITE_NOT_FOUND);
		}

		$ownerTypeId = $current->ownerTypeId;
		$ownerId = $current->ownerId;
		if (!$this->permissions->canDelete($ownerTypeId, $ownerId, $userId))
		{
			return self::absence(self::ERROR_REQUISITE_NOT_FOUND);
		}

		try
		{
			$this->eraseWhatBelongsToRequisite($id);
			$this->requisites->delete($id);
		}
		catch (\Throwable $refusal)
		{
			return self::fail($this->refusals->map($refusal, [
				'requisiteId' => $id,
				'ownerTypeId' => $ownerTypeId,
				'ownerId' => $ownerId,
			]));
		}

		$this->effects->afterDelete($id, $ownerTypeId, $ownerId);

		return new Result();
	}

	/**
	 * The creation as `\Bitrix\Crm\EntityRequisite::add()` performs it: no permission, no validation of
	 * the fields against the preset, and the effects at the moment they run today.
	 *
	 * Whatever the set carries is written as it stands - the substituted system fields among them, so the
	 * author of a creation stays whatever legacy put there. The owner the effects address is read out of
	 * the set, the way legacy reads it, which is why a requisite of a lead or of no owner at all still
	 * gets its event and nothing else.
	 *
	 * @param array<string, mixed> $eventFields the payload of the event, stated rather than derived - see
	 *        {@see RequisiteEffects} for why the typed set cannot reproduce it.
	 * @param (callable(int): void)|null $afterWrite what legacy does between the row and the effects and
	 *        keeps doing: registering the nested addresses, dropping the user fields of an address-only
	 *        requisite, erasing the files a write replaced. Called with the identifier of the written
	 *        requisite, and only when there is one.
	 * @param array<string, mixed> $userFieldValues the values of the write that are no column of this
	 *        area - see {@see RequisiteWriteRepository::insert()}.
	 */
	public function addForLegacyFacade(
		RequisiteWriteData $fields,
		array $eventFields,
		?callable $afterWrite = null,
		array $userFieldValues = [],
	): Result
	{
		try
		{
			$id = $this->requisites->insert($fields, $userFieldValues);
		}
		catch (\Throwable $refusal)
		{
			return self::boundaryRefusal($refusal);
		}

		if ($afterWrite !== null)
		{
			$afterWrite($id);
		}

		$ownerTypeId = self::intOf($fields, Requisite::FIELD_OWNER_TYPE_ID);
		$ownerId = self::intOf($fields, Requisite::FIELD_OWNER_ID);

		$this->effects->afterAdd($ownerTypeId, $ownerId, $id, $eventFields);

		return (new Result())->setData([self::DATA_KEY_ID => $id]);
	}

	/**
	 * The change as `\Bitrix\Crm\EntityRequisite::update()` performs it, including the one thing the
	 * published operation refuses: moving a requisite to another owner. The set names the owner, and the
	 * effects reindex the duplicates of both the owner it left and the owner it arrived at, plus the
	 * duplicates of its bank details, which only a move touches.
	 *
	 * The cache of the selector is dropped even when the storage refuses the write, which is where legacy
	 * put that line and a difference in the safe direction: the published operation drops it only when the
	 * write went through.
	 *
	 * The row is handed in rather than read here, and that is the whole difference from the published
	 * line: the facade reads the same row anyway - for the address-only mark and for the files a write
	 * replaces - so one read of it answers both, and this line no longer costs a read more than legacy.
	 *
	 * @param ?RequisiteWriteTarget $current the row as the caller read it. The present caller never hands
	 *        `null` down: the facade refuses on a row it did not find and does not reach this call, and the
	 *        line is closed to new consumers. So the refusal of an absent row below is a guard for another
	 *        caller rather than an answer this line still gives.
	 * @param array<string, mixed> $eventFields see {@see self::addForLegacyFacade()}.
	 * @param (callable(int): void)|null $afterWrite see {@see self::addForLegacyFacade()}.
	 * @param array<string, mixed> $userFieldValues see {@see self::addForLegacyFacade()}.
	 */
	public function updateForLegacyFacade(
		int $id,
		?RequisiteWriteTarget $current,
		RequisiteWriteData $fields,
		array $eventFields,
		?callable $afterWrite = null,
		array $userFieldValues = [],
	): Result
	{
		if ($current === null)
		{
			// Worded, unlike the same refusal on the published line, where the phrase is put in by
			// `RequisiteError::toError()`: the consumers of the facade read `getErrorMessages()`.
			return self::fail(new Error('Requisite not found', self::ERROR_REQUISITE_NOT_FOUND));
		}

		try
		{
			$this->requisites->update($id, $fields, $userFieldValues);
		}
		catch (\Throwable $refusal)
		{
			$this->effects->dropSelectorCacheOfRequisite($id);

			return self::boundaryRefusal($refusal);
		}

		if ($afterWrite !== null)
		{
			$afterWrite($id);
		}

		// Named with no value counts as not named, the way legacy reads the same two keys: a write that
		// empties the owner of a requisite is not a write that moves it to another one.
		$namedOwnerTypeId = self::namedInt($fields, Requisite::FIELD_OWNER_TYPE_ID);
		$namedOwnerId = self::namedInt($fields, Requisite::FIELD_OWNER_ID);

		$this->effects->afterUpdate(
			$id,
			$current->ownerTypeId,
			$current->ownerId,
			$namedOwnerTypeId,
			$namedOwnerId,
			$eventFields,
		);

		return new Result();
	}

	/**
	 * The deletion as `\Bitrix\Crm\EntityRequisite::delete()` performs it: the same order of steps, the
	 * refusal of the bank details lost the way it is lost today, and success plus an event for an identifier
	 * that is not a row at all.
	 *
	 * The identifier is taken as it was given, because legacy casts it nowhere: a non-positive one and a
	 * string that is no number are refused by the unregistration of the links, while a fractional one goes
	 * through to the storage as the primary key of its integer part and into the payload of the event as
	 * itself. The cache of the selector is dropped whether the row went or not, as in {@see
	 * self::updateForLegacyFacade()}.
	 *
	 * @param int|float|string $id the identifier as the caller stated it.
	 * @param (callable(int): void)|null $afterWrite see {@see self::addForLegacyFacade()}; here it is the
	 *        files of the signature and the stamp, which legacy erases after the row and before the
	 *        criteria.
	 */
	public function deleteForLegacyFacade(int|float|string $id, ?callable $afterWrite = null): Result
	{
		$requisiteId = (int)$id;
		$current = $this->requisites->findWriteTarget($requisiteId);

		// Outside the guard below on purpose: legacy lets these three throw, and a non-positive identifier
		// is refused by the first of them before anything is erased.
		EntityLink::unregisterByRequisite($id);
		RequisiteAddress::deleteByEntityId($id);
		EntityBankDetail::getSingleInstance()->deleteByEntity(OwnerType::REQUISITE, $id);

		try
		{
			$this->requisites->delete($requisiteId);
		}
		catch (\Throwable $refusal)
		{
			$this->effects->dropSelectorCacheOfRequisite($requisiteId);

			return self::boundaryRefusal($refusal);
		}

		if ($afterWrite !== null)
		{
			$afterWrite($requisiteId);
		}

		$this->effects->afterDelete(
			$id,
			$current === null ? OwnerType::UNDEFINED : $current->ownerTypeId,
			$current === null ? 0 : $current->ownerId,
		);

		return new Result();
	}

	/**
	 * `null` when the owner is one $userId may add a requisite to, and the single absence otherwise.
	 *
	 * Three questions in the order they have to be asked: a type no requisite belongs to, a user who may
	 * not reach this owner, an owner that is not there. All three answer the same, so the order is not
	 * about the answer - it is about not asking a permission over a type the model has no permission for
	 * and not reading a row before the user is allowed to know of it.
	 */
	private function refuseUnavailableOwner(int $ownerTypeId, int $ownerId, int $userId): ?Result
	{
		$available =
			$this->owners->isSupportedType($ownerTypeId)
			&& $this->permissions->canAdd($ownerTypeId, $ownerId, $userId)
			&& $this->owners->exists($ownerTypeId, $ownerId)
		;

		return $available ? null : self::absence(self::ERROR_OWNER_NOT_FOUND);
	}


	/**
	 * Erases what a requisite owns, in the order it has always been erased, and lets no refusal of it
	 * pass for success.
	 *
	 * The bank details are the one step that answers with a result instead of throwing, and its refusal
	 * is lost by legacy on purpose and by us not at all. One of its refusals is not one: a requisite with
	 * no bank details at all is answered "nothing to delete", and that is the ordinary case rather than a
	 * failure - treating it as one would refuse the deletion of most requisites of the portal.
	 *
	 * @throws SystemException
	 */
	private function eraseWhatBelongsToRequisite(int $id): void
	{
		EntityLink::unregisterByRequisite($id);
		RequisiteAddress::deleteByEntityId($id);

		$erased = EntityBankDetail::getSingleInstance()->deleteByEntity(OwnerType::REQUISITE, $id);
		$messages = [];
		foreach ($erased->getErrors() as $error)
		{
			if ((int)$error->getCode() === EntityBankDetail::ERR_NOTHING_TO_DELETE)
			{
				continue;
			}

			$messages[] = trim((string)$error->getMessage());
		}

		if ($messages !== [])
		{
			throw new SystemException(
				sprintf('The storage refused to erase the bank details of a requisite: %s', implode('; ', $messages)),
			);
		}
	}

	/**
	 * The set of fields in the vocabulary the event of the domain has always spoken: the names of the
	 * columns, and the letters a boolean column keeps.
	 *
	 * @return array<string, mixed>
	 */
	private static function eventFieldsOf(RequisiteWriteData $fields): array
	{
		$eventFields = [];
		foreach ($fields->toArray() as $field => $value)
		{
			$eventFields[RequisiteColumnMap::requireWriteColumn($field)] = is_bool($value)
				? ($value ? self::STORED_TRUE : self::STORED_FALSE)
				: $value
			;
		}

		return $eventFields;
	}

	/**
	 * The refusal of the boundary as the facade line answers it: what the storage said, carried over
	 * rather than classified, because the consumers of that line read the refusal of the storage and have
	 * done so for as long as they have existed.
	 *
	 * The errors of the storage are forwarded as they are when they came that way
	 * ({@see StorageRefusedException}); anything else refused with a description rather than a list, and
	 * the description is all there is to pass on.
	 */
	private static function boundaryRefusal(\Throwable $refusal): Result
	{
		if ($refusal instanceof StorageRefusedException && $refusal->getRefusals() !== [])
		{
			return self::failure($refusal->getRefusals());
		}

		return self::fail(new Error($refusal->getMessage()));
	}

	private static function intOf(RequisiteWriteData $fields, string $field): int
	{
		return (int)$fields->get($field);
	}

	/**
	 * The value of $field when the write named one, and `null` when it named the field without a value or
	 * did not name it at all - the distinction `isset()` draws in legacy.
	 */
	private static function namedInt(RequisiteWriteData $fields, string $field): ?int
	{
		return $fields->get($field) === null ? null : self::intOf($fields, $field);
	}

	/**
	 * @param Error[] $errors
	 */
	private static function failure(array $errors): Result
	{
		$result = new Result();
		foreach ($errors as $error)
		{
			$result->addError($error);
		}

		return $result;
	}

	private static function absence(string $code): Result
	{
		return self::fail(self::absentError($code));
	}

	private static function absentError(string $code): Error
	{
		return new Error('', $code);
	}

	private static function fail(Error $error): Result
	{
		return (new Result())->addError($error);
	}
}
