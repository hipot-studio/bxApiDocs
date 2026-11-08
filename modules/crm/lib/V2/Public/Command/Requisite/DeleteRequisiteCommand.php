<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\RequisiteWriteService;
use Bitrix\Crm\V2\Public\Entity\Requisite\RequisiteError;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Result;

/**
 * Deletes a requisite of a CRM contact or company together with what belongs to it - its addresses, its
 * bank details, and its place among the requisites selected for documents and for "my company".
 *
 * ```php
 * $result = (new DeleteRequisiteCommand(EntityType::company(), $requisiteId, $userId))->run();
 * ```
 *
 * The expected type of the owner is stated first and means what it means in
 * {@see UpdateRequisiteCommand}: a requisite of any other kind of record is not the one the caller
 * addressed, and the check runs on the row the deletion reads anyway.
 *
 * The deletion is not atomic: the links of a requisite, its addresses and its bank details are erased
 * before its row, so a storage that refuses the row answers
 * {@see RequisiteError::REQUISITE_OPERATION_FAILED} for a requisite that has already lost all three
 * ({@see AbstractRequisiteCommand}).
 *
 * A requisite that is not there, one of another type of owner, one this user may not delete, and one
 * already deleted are the same single {@see RequisiteError::REQUISITE_NOT_FOUND} - so deleting twice
 * refuses the second time rather than answering success, and nothing is touched and no event goes out on
 * that call. A successful result carries nothing: the requisite is gone, and what went with it is the
 * contract above rather than a report.
 *
 * What happens when the work behind the write refuses - the requisite stays deleted and the caller gets
 * an exception instead of a result - is the contract of {@see AbstractRequisiteCommand}.
 */
final class DeleteRequisiteCommand extends AbstractRequisiteCommand
{
	public function __construct(
		private readonly EntityType $expectedOwnerType,
		private readonly int $requisiteId,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getRequisiteId(): int
	{
		return $this->requisiteId;
	}

	protected function execute(): Result
	{
		return (new RequisiteWriteService())->delete(
			$this->expectedOwnerType->getId(),
			$this->requisiteId,
			$this->getUserId(),
		);
	}
}
