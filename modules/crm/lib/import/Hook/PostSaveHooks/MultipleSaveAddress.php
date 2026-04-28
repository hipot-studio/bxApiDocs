<?php

namespace Bitrix\Crm\Import\Hook\PostSaveHooks;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\Import\Contract\PostSaveHookInterface;
use Bitrix\Crm\Import\Dto\Hook\PostSaveHooks\MultipleSaveAddressData;
use Bitrix\Crm\Import\Dto\ImportItemsCollection\ImportItem;
use Bitrix\Crm\Item;
use Bitrix\Crm\Result;

final class MultipleSaveAddress implements PostSaveHookInterface
{
	public function __construct(
		/** @var class-string<EntityAddress> $entityAddress */
		private readonly string $entityAddress = EntityAddress::class,
	)
	{
	}

	public function execute(Item $item, ImportItem $importItem): Result
	{
		/** @var MultipleSaveAddressData[] $addresses */
		$addresses = $importItem->values['ADDRESSES'] ?? [];
		foreach ($addresses as $addressData)
		{
			if (!$addressData instanceof MultipleSaveAddressData)
			{
				continue;
			}

			$addressValues = $this->fillAddressCountryByCode($addressData->getAddressValues());

			$this->entityAddress::register(
				entityTypeID: $item->getEntityTypeID(),
				entityID: $item->getId(),
				typeID: $addressData->getAddressType(),
				data: $addressValues,
			);
		}

		return new Result();
	}

	private function fillAddressCountryByCode(array $addressValues): array
	{
		$countryCode = $addressValues[FieldName::COUNTRY_CODE] ?? null;
		$country = $addressValues[FieldName::COUNTRY] ?? null;

		if ($countryCode !== null && $country === null)
		{
			$countryByCode = GetCountries()[mb_strtoupper($countryCode)] ?? null;
			$countryName = $countryByCode['NAME'] ?? null;

			if (!empty($countryName))
			{
				$addressValues[FieldName::COUNTRY] = $countryName;
				unset($addressValues[FieldName::COUNTRY_CODE]);
			}
		}

		return $addressValues;
	}
}
