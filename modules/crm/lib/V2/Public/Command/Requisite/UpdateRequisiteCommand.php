<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Changes a requisite of a CRM contact or company.
 *
 * ```php
 * $changes = (new Requisite())->setInn('7701234567')->setKpp(null);
 * $result = (new UpdateRequisiteCommand(EntityType::company(), $requisiteId, $changes, $userId))->run();
 * ```
 *
 * The expected type of the owner is the first thing a change states, the way the writes of the bank
 * details of the group state it ({@see UpdateBankRequisiteCommand}): a caller reaches the requisites of
 * one kind of record at a time, and a requisite of any other kind is not the one it addressed. The type is
 * an expectation and never a value to write - no command of this group moves a requisite between owners -
 * and it is checked on the row the change reads anyway, so stating it costs a caller nothing.
 *
 * The identifier says which requisite is meant and the requisite handed in says what to write: the fields
 * a setter was called on, each with the value it was given. A field left alone keeps its stored value; a
 * field named and left empty is cleared, which is the only way a client takes back a legal detail it
 * entered before. The owner of a requisite is not among the fields a change carries - it is the address of
 * the record and cannot be set here or anywhere else in the group.
 *
 * A CHANGE THAT CARRIES NOTHING is a successful operation that writes nothing at all - not even the mark
 * of the modification - and it still answers for the requisite: one that is not there, or that this user
 * may not change, is refused as it would be for any other change. So
 * {@see Requisite::hasChangedFields()} is worth asking before the call only to save a query, never to
 * avoid a different answer.
 *
 * A requisite that is not there, one this user may not change, and one belonging to another owner - one
 * under an owner of another type among them - are the same single
 * {@see RequisiteError::REQUISITE_NOT_FOUND}. Naming a preset the portal does not offer is
 * {@see RequisiteError::PRESET_NOT_FOUND}; naming one it does is allowed and keeps the values that fall
 * outside the new preset, the way the card of a requisite does. A field the preset does not declare is
 * {@see RequisiteError::FIELD_NOT_IN_PRESET}, one it requires and the call empties is
 * {@see RequisiteError::FIELD_REQUIRED} - and a required field the call does not name at all is not
 * refused, because a change answers for what it carries - a value longer than its cell is
 * {@see RequisiteError::FIELD_TOO_LONG}, and a storage that would not write the row is
 * {@see RequisiteError::REQUISITE_OPERATION_FAILED}.
 *
 * On success the requisite comes back with its change marks dropped and its values kept, ready to be
 * given to the next change without repeating this one.
 *
 * What happens when the work behind the write refuses - the change stays written and the caller gets an
 * exception instead of a result - is the contract of {@see AbstractRequisiteCommand}.
 */
final class UpdateRequisiteCommand extends AbstractRequisiteCommand
{
	public function __construct(
		private readonly EntityType $expectedOwnerType,
		private readonly int $requisiteId,
		private readonly Requisite $changes,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getRequisiteId(): int
	{
		return $this->requisiteId;
	}

	public function getChanges(): Requisite
	{
		return $this->changes;
	}

	protected function execute(): Result
	{
		$result = (new RequisiteWriteService())->update(
			$this->expectedOwnerType->getId(),
			$this->requisiteId,
			RequisiteWriteData::of(self::fieldsToWrite($this->changes)),
			$this->getUserId(),
		);

		return self::applyChange($this->changes, $result);
	}
}
