<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\RequisiteLinkDto;

final class RequisiteLinkMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return RequisiteLinkDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'entityTypeId' => ['ENTITY_TYPE_ID', self::INT],
			'entityId' => ['ENTITY_ID', self::INT],
			'requisiteId' => ['REQUISITE_ID', self::INT],
			'bankDetailId' => ['BANK_DETAIL_ID', self::INT],
			'mcRequisiteId' => ['MC_REQUISITE_ID', self::INT],
			'mcBankDetailId' => ['MC_BANK_DETAIL_ID', self::INT],
		];
	}
}
