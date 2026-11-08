<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Application;
use Bitrix\Main\License;
use Bitrix\Main\License\UrlProvider;
use Bitrix\Main\Loader;
use Bitrix\Rest\Marketplace\Url;

class PurchaseUrlProvider
{
	protected License $license;
	private UrlProvider $urlProvider;

	public function __construct(?License $license = null, ?UrlProvider $urlProvider = null)
	{
		$this->license = $license ?? Application::getInstance()->getLicense();
		$this->urlProvider = $urlProvider ?? new UrlProvider();
	}

	public function getTariffBuyUrl(): string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return \CBitrix24::PATH_LICENSE_ALL;
		}

		return $this->urlProvider->getPriceTableUrl()->getUri();
	}

	public function getSubscriptionBuyUrl(): ?string
	{
		if (Loader::includeModule('bitrix24'))
		{
			return Loader::includeModule('rest') ? Url::getSubscriptionBuyUrl() : null;
		}

		if ($this->license->getRegion() !== 'ru')
		{
			return null;
		}

		return $this->urlProvider->getPriceTableUrl()->getUri() . '#tab-section-5';
	}
}
