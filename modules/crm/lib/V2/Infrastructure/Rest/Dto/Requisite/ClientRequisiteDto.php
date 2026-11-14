<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\ClientRequisiteDtoMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;

/**
 * The choice made among the requisites of the client of a CRM entity: the pair of
 * {@see AbstractRequisitePairDto}, read and written under the name of this group.
 *
 * The requisites of the seller are a separate choice of the same entity, carried by
 * {@see MyCompanyRequisiteDto}.
 */
#[MappedBy(ClientRequisiteDtoMapper::class)]
final class ClientRequisiteDto extends AbstractRequisitePairDto
{
}
