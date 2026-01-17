<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Currency;

use Bitrix\Currency\Helpers\Editor;
use Bitrix\Intranet\Internal\Integration\Currency\Entity\Currency;
use Bitrix\Intranet\Internal\Integration\Currency\Entity\CurrencyCollection;
use Bitrix\Main\Loader;

class CurrencyProvider
{
	private ?CurrencyCollection $availableCurrencyCollection = null;

	public static function isAvailable(): bool
	{
		return Loader::includeModule('currency');
	}

	public function getAvailableCurrencyCollection(): CurrencyCollection
	{
		if (isset($this->availableCurrencyCollection))
		{
			return $this->availableCurrencyCollection;
		}

		$this->availableCurrencyCollection = new CurrencyCollection();

		if (!self::isAvailable())
		{
			return $this->availableCurrencyCollection;
		}

		$rawList = Editor::getListCurrency();

		foreach ($rawList as $rawCurrency)
		{
			if (is_array($rawCurrency))
			{
				$this->availableCurrencyCollection->add(
					Currency::createFromArray($rawCurrency),
				);
			}
		}

		return $this->availableCurrencyCollection;
	}
}
