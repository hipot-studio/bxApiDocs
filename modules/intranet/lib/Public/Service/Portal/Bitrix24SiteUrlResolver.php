<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Service\Portal;

use Bitrix\Intranet\Internal\Integration\Bitrix24\PortalInfoService;

final class Bitrix24SiteUrlResolver
{
	public function resolve(): string
	{
		$licenseZone = (new PortalInfoService())->getPortalZone();
		$siteLanguage = '';
		if ($licenseZone === 'md')
		{
			$site = \CSite::GetByID(SITE_ID)->Fetch();
			$siteLanguage = $site['LANGUAGE_ID'] ?? '';
		}

		return $this->resolveFor($licenseZone, $siteLanguage, LANGUAGE_ID);
	}

	private function resolveFor(string $licenseZone, string $siteLanguage, string $interfaceLanguage): string
	{
		if ($licenseZone === 'md' && $siteLanguage === 'en')
		{
			return 'https://www.bitrix24.md/';
		}

		return match ($interfaceLanguage)
		{
			'ru' => 'https://www.bitrix24.ru',
			'de' => 'https://www.bitrix24.de',
			'kz' => 'https://www.bitrix24.kz',
			default => 'https://www.bitrix24.com',
		};
	}
}
