<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\MyCompanyRequisiteDto;
use Bitrix\Crm\V2\Public\Entity\Requisite\MyCompanyRequisite;

/**
 * @extends AbstractRequisitePairDtoMapper<MyCompanyRequisite>
 */
final class MyCompanyRequisiteDtoMapper extends AbstractRequisitePairDtoMapper
{
	protected function dtoClass(): string
	{
		return MyCompanyRequisiteDto::class;
	}

	protected function createEntity(?int $requisiteId, ?int $bankRequisiteId): MyCompanyRequisite
	{
		return new MyCompanyRequisite($requisiteId, $bankRequisiteId);
	}
}
