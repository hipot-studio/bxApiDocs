<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\MyCompanyRequisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\MyCompanyRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\AbstractRequisitePairSetRequest;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisite;

/**
 * A write of the choice made among the requisites of the seller - of the company a CRM entity names
 * as "my company".
 *
 * @extends AbstractRequisitePairSetRequest<MyCompanyRequisite>
 */
final class SetRequest extends AbstractRequisitePairSetRequest
{
	protected static function pairMapper(): MyCompanyRequisiteDtoMapper
	{
		return new MyCompanyRequisiteDtoMapper();
	}
}
