<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite\MyCompanyRequisiteDtoMapper;
use Bitrix\Rest\V3\Attribute\MappedBy;

/**
 * The choice made among the requisites of the seller - of the company a CRM entity names as "my
 * company": the pair of {@see AbstractRequisitePairDto}, read and written under the name of this
 * group.
 *
 * The requisites of the client are a separate choice of the same entity, carried by
 * {@see ClientRequisiteDto}.
 */
#[MappedBy(MyCompanyRequisiteDtoMapper::class)]
final class MyCompanyRequisiteDto extends AbstractRequisitePairDto
{
}
