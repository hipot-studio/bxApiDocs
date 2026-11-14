<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Requisite;

use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisiteError;

/**
 * The answer to a refused choice made among the requisites of the company a CRM entity names as "my
 * company": the table of {@see AbstractRequisitePairErrorMapper} over {@see MyCompanyRequisiteError}.
 */
final class MyCompanyRequisiteErrorMapper extends AbstractRequisitePairErrorMapper
{
	protected function ownerNotFoundRefusal(): MyCompanyRequisiteError
	{
		return MyCompanyRequisiteError::OWNER_NOT_FOUND;
	}

	protected function fieldByRefusal(): array
	{
		return [
			MyCompanyRequisiteError::REQUISITE_NOT_ALLOWED->value => 'fields.requisiteId',
			MyCompanyRequisiteError::BANK_REQUISITE_NOT_ALLOWED->value => 'fields.bankRequisiteId',
		];
	}
}
