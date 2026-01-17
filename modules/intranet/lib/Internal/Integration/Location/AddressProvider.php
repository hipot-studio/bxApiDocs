<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Location;

use Bitrix\Location\Entity\Address;
use Bitrix\Main\Loader;

class AddressProvider
{
	public static function isAvailable(): bool
	{
		return Loader::includeModule('location');
	}

	public function getById(int $id): ?AddressAdapter
	{
		if (!static::isAvailable() || $id < 0)
		{
			return null;
		}

		try
		{
			$address = Address::load($id);
		}
		catch (\Exception)
		{
			return null;
		}

		return $address instanceof Address ? new AddressAdapter($address) : null;
	}

	public function getByUserFieldValue(string $value): ?AddressAdapter
	{
		$values = explode('|', $value);
		$valueId = (int)end($values);

		return $this->getById($valueId);
	}
}
