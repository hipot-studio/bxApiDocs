<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Service\AiAssistant\Mapper;

use Bitrix\Booking\Entity\Sku\Sku;

class SkuMapper
{
	public function mapFromEntity(Sku $sku): array
	{
		return [
			'id' => $sku->getId(),
			'name' => $sku->getName(),
			'price' => $sku->getPrice(),
			'currencyId' => $sku->getCurrencyId(),
		];
	}
}
