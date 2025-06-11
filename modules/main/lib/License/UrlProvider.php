<?php

namespace Bitrix\Main\License;

use Bitrix\Main\Application;
use Bitrix\Main\Web\Uri;

class UrlProvider
{
	private const STORE_DOMAINS = [
		'ru' => 'www.1c-bitrix.ru',
		'by' => 'www.1c-bitrix.by',
		'kz' => 'www.1c-bitrix.kz',
		'en' => 'store.bitrix24.com',
		'de' => 'store.bitrix24.de',
		'eu' => 'store.bitrix24.eu',
	];
	private const PRODUCTS_DOMAINS = [
		'ru' => 'www.1c-bitrix.ru',
		'by' => 'www.1c-bitrix.by',
		'kz' => 'www.1c-bitrix.kz',
		'en' => 'www.bitrix24.com',
		'de' => 'www.bitrix24.de',
		'eu' => 'www.bitrix24.eu',
		'in' => 'www.bitrix24.in',
	];
	private const TECH_DOMAINS = [
		'ru' => 'bitrix24.tech',
		'by' => 'bitrix24.tech',
		'kz' => 'bitrix24.tech',
		'en' => 'bitrix.info',
	];
	private const FEEDBACK_DOMAINS = [
		'ru' => 'product-feedback.bitrix24.ru',
		'en' => 'product-feedback.bitrix24.com',
	];

	public function getPriceTableUrl(): Uri
	{
		$license = Application::getInstance()->getLicense();
		$domain = self::PRODUCTS_DOMAINS[$license->getRegion() ?? 'en'] ?? self::PRODUCTS_DOMAINS['en'];
		$url = new Uri('https://' . $domain);

		if (in_array($license->getRegion(), ['ru', 'by', 'kz']))
		{
			$url->setPath('/buy/products/b24.php');
		}
		else
		{
			$url->setPath('/prices/self-hosted.php');
		}

		return $url;
	}

	public function getPurchaseHistoryUrl(): Uri
	{
		$license = Application::getInstance()->getLicense();
		$domain = self::STORE_DOMAINS[$license->getRegion() ?? 'en'] ?? self::STORE_DOMAINS['en'];
		$url = new Uri('https://' . $domain);

		if (in_array($license->getRegion(), ['ru', 'by', 'kz']))
		{
			$url->setPath('/support/key_info.php');
		}
		else
		{
			$url->setPath('/profile/license-keys.php');
		}

		return $url;
	}

	public function getMailingAgreementUrl(): ?Uri
	{
		$region = Application::getInstance()->getLicense()->getRegion();

		if (in_array($region, ['ru', 'by', 'kz']))
		{
			return new Uri("https://www.bitrix24.$region/about/advertising.php");
		}

		return null;
	}

	public function getProductDomain(?string $region = null): Uri
	{
		$region ??= Application::getInstance()->getLicense()->getRegion();
		$domain = self::PRODUCTS_DOMAINS[$region ?? 'en'] ?? self::PRODUCTS_DOMAINS['en'];

		return new Uri('https://' . $domain);
	}

	public function getTechDomain(?string $region = null): string
	{
		$region ??= Application::getInstance()->getLicense()->getRegion();
		$domain = self::TECH_DOMAINS[$region ?? 'en'] ?? self::TECH_DOMAINS['en'];

		return $domain;
	}

	public function getFeedbackDomain(?string $region = null): string
	{
		$region ??= Application::getInstance()->getLicense()->getRegion();
		$domain = self::FEEDBACK_DOMAINS[$region ?? 'en'] ?? self::FEEDBACK_DOMAINS['en'];

		return $domain;
	}
}
