<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Balance;

use Bitrix\Main\Application;
use Bitrix\Main\Service\MicroService\Client;

final class PurchaseUrlProvider
{
	private const CLOUD_URL = '/settings/order/make.php?product=VIBECODE_TEAM';
	private const BOX_URLS = [
		'ru' => 'https://www.1c-bitrix.ru/buy_tmp/order_vibe_box.php',
		'by' => 'https://www.1c-bitrix.by/buy_tmp/order_vibe_box.php',
		'kz' => 'https://www.1c-bitrix.kz/buy_tmp/order_vibe_box.php',
		'uz' => 'https://www.1c-bitrix.uz/buy_tmp/order_vibe_box.php',
	];
	private const BOX_DEFAULT_URL = 'https://store.bitrix24.com/tobasket.php';

	public function __construct(
		private readonly ?string $portalType = null,
		private readonly ?string $region = null,
		private readonly ?string $publicHashKey = null,
	)
	{
	}

	public function getUrl(): string
	{
		if (($this->portalType ?? Client::getPortalType()) === Client::TYPE_BITRIX24)
		{
			return self::CLOUD_URL;
		}

		if ($this->region !== null && $this->publicHashKey !== null)
		{
			$region = $this->region;
			$publicHashKey = $this->publicHashKey;
		}
		else
		{
			$license = Application::getInstance()->getLicense();
			$region = $this->region ?? $license->getRegion() ?? '';
			$publicHashKey = $this->publicHashKey ?? $license->getPublicHashKey();
		}

		$region = strtolower($region);
		$baseUrl = self::BOX_URLS[$region] ?? self::BOX_DEFAULT_URL;

		return $baseUrl . '?vibe_client_type=box&vibe_bx_licence=' . rawurlencode($publicHashKey);
	}
}
