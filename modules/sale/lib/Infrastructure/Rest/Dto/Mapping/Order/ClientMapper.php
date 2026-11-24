<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\ClientDto;

final class ClientMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return ClientDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'entityTypeId' => ['ENTITY_TYPE_ID', self::INT],
			'entityId' => ['ENTITY_ID', self::INT],
			'roleId' => ['ROLE_ID', self::INT],
			'isPrimary' => ['IS_PRIMARY', self::BOOL],
			'sort' => ['SORT', self::INT],
		];
	}
}
