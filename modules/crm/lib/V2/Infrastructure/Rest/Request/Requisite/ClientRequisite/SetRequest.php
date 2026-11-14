<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\ClientRequisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\ClientRequisiteDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Requisite\AbstractRequisitePairSetRequest;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;

/**
 * A write of the choice made among the requisites of the client of a CRM entity.
 *
 * @extends AbstractRequisitePairSetRequest<ClientRequisite>
 */
final class SetRequest extends AbstractRequisitePairSetRequest
{
	protected static function pairMapper(): ClientRequisiteDtoMapper
	{
		return new ClientRequisiteDtoMapper();
	}
}
