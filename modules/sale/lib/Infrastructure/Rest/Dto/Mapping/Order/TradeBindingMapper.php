<?php

declare(strict_types=1);

namespace Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\Order;

use Bitrix\Sale\Infrastructure\Rest\Dto\Mapping\AbstractOrderMapper;
use Bitrix\Sale\Infrastructure\Rest\Dto\Order\TradeBindingDto;

final class TradeBindingMapper extends AbstractOrderMapper
{
	protected function getDtoClass(): string
	{
		return TradeBindingDto::class;
	}

	protected function getFieldMap(): array
	{
		return [
			'id' => ['ID', self::INT],
			'tradingPlatformId' => ['TRADING_PLATFORM_ID', self::INT],
			'tradingPlatformXmlId' => ['TRADING_PLATFORM_XML_ID', self::STRING],
			'externalOrderId' => ['EXTERNAL_ORDER_ID', self::STRING],
			// The column is serialized, so a platform that writes a structure reads one back.
			'params' => ['PARAMS', self::RAW],
			'xmlId' => ['XML_ID', self::STRING],
		];
	}
}
