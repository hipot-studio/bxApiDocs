<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Entity\Requisite\RequisiteWriteData;
use Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\Requisite;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * Creates a requisite of a CRM contact or company.
 *
 * ```php
 * $requisite = (new Requisite())
 *     ->setOwnerTypeId(OwnerType::COMPANY)
 *     ->setOwnerId($companyId)
 *     ->setPresetId($presetId)
 *     ->setName('OOO Vector')
 *     ->setInn('7701234567')
 * ;
 * $result = (new AddRequisiteCommand($requisite, $userId))->setScope(Scope::Rest)->run();
 * // $requisite->getId() on success
 * ```
 *
 * The requisite says whom it is for and under which preset, and the fields set on it are what the write
 * carries. On success the same requisite receives the identifier it was written under and comes back
 * without change marks.
 *
 * ADDRESS AND CONTENT REFUSE DIFFERENTLY. The owner is the address of the creation: a requisite that names
 * none is a call that means nothing, and it is an {@see ArgumentException} rather than a refusal. The
 * preset is content - a field of the record, marked and written like any other - so a creation that names
 * no preset, or one the portal does not offer, is refused with {@see RequisiteError::PRESET_NOT_FOUND}
 * naming the field, which is an answer a client can correct.
 *
 * An owner that is not there, one this user may not reach and one of a type no requisite belongs to are
 * the same single {@see RequisiteError::OWNER_NOT_FOUND}. A field the preset does not declare is refused
 * with {@see RequisiteError::FIELD_NOT_IN_PRESET}, a field the preset requires and the call left empty
 * with {@see RequisiteError::FIELD_REQUIRED}, a value longer than its cell with
 * {@see RequisiteError::FIELD_TOO_LONG} carrying the limit, and a storage that would not write the row
 * with {@see RequisiteError::REQUISITE_OPERATION_FAILED}. Every refusal of a field names it, and a call
 * that got several fields wrong is told about all of them at once.
 *
 * The author and the moment of the creation are the portal's to fill in: the user this command was built
 * with, and now.
 *
 * What happens when the work behind the write refuses - the requisite stays created and the caller gets
 * an exception instead of a result - is the contract of {@see AbstractRequisiteCommand}.
 *
 * BEYOND THE REQUEST. The second known consumer of this command is a business process node that creates a
 * requisite. It arrives with no request behind it, so the command takes a model rather than the fields of
 * a request, and it asks nothing of the transport: the user it answers for is an argument, and the scope
 * of an automated run is {@see \Bitrix\Crm\V2\Public\Command\Item\Scope::Automation}. Whatever such a
 * consumer needs beyond that is added as an optional argument with a default that keeps the present
 * behaviour, never as a required one.
 */
final class AddRequisiteCommand extends AbstractRequisiteCommand
{
	public function __construct(
		private readonly Requisite $requisite,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getRequisite(): Requisite
	{
		return $this->requisite;
	}

	protected function execute(): Result
	{
		$result = (new RequisiteWriteService())->add(
			self::ownerTypeIdOf($this->requisite),
			self::ownerIdOf($this->requisite),
			$this->requisite->getPresetId() ?? 0,
			RequisiteWriteData::of(self::fieldsToWrite($this->requisite)),
			$this->getUserId(),
		);

		return self::applyCreation($this->requisite, $result);
	}
}
