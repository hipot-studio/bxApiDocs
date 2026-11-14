<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\Application\EInvoice;

use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;

class ApplicationTagResolver
{
	private const MULTI_COUNTRY_REGIONS = ['en', 'eu', 'la'];

	public function getCodes(): array
	{
		$region = mb_strtolower($this->getRegion() ?? '');
		$country = mb_strtolower($this->getRegistrationCountry());

		if (in_array($region, self::MULTI_COUNTRY_REGIONS, true) && $country !== '' && $country !== $region)
		{
			return [$region, $country];
		}

		return [$region];
	}

	protected function getRegion(): ?string
	{
		return Application::getInstance()->getLicense()->getRegion();
	}

	protected function getRegistrationCountry(): string
	{
		return Option::get('bitrix24', 'REG_COUNTRY', '');
	}
}
