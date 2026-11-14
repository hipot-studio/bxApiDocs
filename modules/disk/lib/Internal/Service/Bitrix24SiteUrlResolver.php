<?php

declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service;

use Bitrix\Main\DI\ServiceLocator;

/**
 * Address of the public Bitrix24 site the logos of documents, boards and the ONLYOFFICE editor lead to.
 */
final class Bitrix24SiteUrlResolver
{
	private const INTERNATIONAL_URL = 'https://bitrix24.com';

	private const ZONE_URLS = [
		'com' => 'https://bitrix24.com',
		'en' => 'https://bitrix24.com',
		'in' => 'https://bitrix24.in',
		'hi' => 'https://bitrix24.in',
		'eu' => 'https://bitrix24.eu',
		'de' => 'https://bitrix24.de',
		'es' => 'https://bitrix24.es',
		'la' => 'https://bitrix24.es',
		'br' => 'https://bitrix24.com.br',
		'pl' => 'https://bitrix24.pl',
		'it' => 'https://bitrix24.it',
		'fr' => 'https://bitrix24.fr',
		'cn' => 'https://bitrix24.cn',
		'sc' => 'https://bitrix24.cn',
		'tc' => 'https://bitrix24.cn',
		'jp' => 'https://bitrix24.jp',
		'ja' => 'https://bitrix24.jp',
		'vn' => 'https://bitrix24.vn',
		'tr' => 'https://bitrix24.com.tr',
		'id' => 'https://bitrix24.id',
		'my' => 'https://bitrix24.com',
		'ms' => 'https://bitrix24.com',
		'th' => 'https://bitrix24.com',
		'ru' => 'https://bitrix24.ru',
		'by' => 'https://bitrix24.by',
		'kz' => 'https://bitrix24.kz',
		'uz' => 'https://bitrix24.uz',
		'md' => 'https://bitrix24.md',
		'uk' => 'https://bitrix24.uk',
		'co' => 'https://bitrix24.co',
		'mx' => 'https://bitrix24.mx',
		'ae' => 'https://bitrix24.ae',
		'ar' => 'https://bitrix24.ae',
	];

	public function __construct(
		private readonly Environment $environment,
	)
	{
	}

	public static function create(): self
	{
		return new self(ServiceLocator::getInstance()->get(Environment::class));
	}

	public function isCloudPortal(): bool
	{
		return $this->environment->isCloudPortal();
	}

	public function getUrl(): string
	{
		if (!$this->isCloudPortal())
		{
			return self::INTERNATIONAL_URL;
		}

		return
			self::ZONE_URLS[$this->environment->getPortalZone()]
			?? self::ZONE_URLS[(string)$this->environment->getLicenseRegion()]
			?? self::INTERNATIONAL_URL;
	}
}
