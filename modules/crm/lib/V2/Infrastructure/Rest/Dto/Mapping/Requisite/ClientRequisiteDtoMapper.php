<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Requisite\ClientRequisiteDto;
use Bitrix\Crm\V2\Public\Entity\Requisite\ClientRequisite;

/**
 * @extends AbstractRequisitePairDtoMapper<ClientRequisite>
 */
final class ClientRequisiteDtoMapper extends AbstractRequisitePairDtoMapper
{
	protected function dtoClass(): string
	{
		return ClientRequisiteDto::class;
	}

	protected function createEntity(?int $requisiteId, ?int $bankRequisiteId): ClientRequisite
	{
		return new ClientRequisite($requisiteId, $bankRequisiteId);
	}
}
