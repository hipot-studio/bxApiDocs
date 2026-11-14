<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite;

use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisiteError;

/**
 * The answer to a refused choice made among the requisites of the client of a CRM entity: the table of
 * {@see AbstractRequisitePairErrorMapper} over {@see ClientRequisiteError}.
 */
final class ClientRequisiteErrorMapper extends AbstractRequisitePairErrorMapper
{
	protected function ownerNotFoundRefusal(): ClientRequisiteError
	{
		return ClientRequisiteError::OWNER_NOT_FOUND;
	}

	protected function fieldByRefusal(): array
	{
		return [
			ClientRequisiteError::REQUISITE_NOT_ALLOWED->value => 'fields.requisiteId',
			ClientRequisiteError::BANK_REQUISITE_NOT_ALLOWED->value => 'fields.bankRequisiteId',
		];
	}
}
