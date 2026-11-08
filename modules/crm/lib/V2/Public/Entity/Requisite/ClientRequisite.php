<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Requisite;

/**
 * The requisite of the client chosen in a CRM entity, together with the bank requisite chosen next
 * to it. `null` means nothing is chosen.
 *
 * The pair is set as a whole: a bank requisite belongs to the requisite standing beside it and
 * cannot outlive it, so a value with a bank requisite and no requisite is not a state the domain
 * accepts. A stored identifier does not promise the record is still there - a requisite may have
 * been deleted after the choice was made, and the pair is read back as it was written.
 *
 * The requisites of the seller are a separate choice of the same entity and are not part of this
 * value.
 */
final class ClientRequisite
{
	public function __construct(
		private readonly ?int $requisiteId = null,
		private readonly ?int $bankRequisiteId = null,
	)
	{
	}

	public function getRequisiteId(): ?int
	{
		return $this->requisiteId;
	}

	public function getBankRequisiteId(): ?int
	{
		return $this->bankRequisiteId;
	}
}
