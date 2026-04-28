<?php

namespace Bitrix\Crm\Import\ImportEntityFields\VCard\DeliveryAddressing;

use Bitrix\Crm\Address\Enum\FieldName;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\Import\Dto\Entity\FieldBindings;
use Bitrix\Crm\Import\Dto\Hook\PostSaveHooks\MultipleSaveAddressData;
use Bitrix\Crm\Import\Hook\PostSaveHooks\MultipleSaveAddress;
use Bitrix\Crm\Import\ImportEntityFields\VCard\AbstractVCardField;
use Bitrix\Crm\Import\Result\FieldProcessResult;
use Bitrix\Crm\VCard\VCardLine;

final class Address extends AbstractVCardField
{
	public const ID = 'ADDRESS';

	public function getId(): string
	{
		return self::ID;
	}

	public function getCaption(): string
	{
		return EntityAddressType::getDescription(EntityAddressType::Primary);
	}

	public function process(array &$importItemFields, FieldBindings $fieldBindings, array $row): FieldProcessResult
	{
		$columnIndex = $fieldBindings->getColumnIndexByFieldId(self::ID);
		if ($columnIndex === null)
		{
			return FieldProcessResult::success();
		}

		$vcardLines = $row[$columnIndex] ?? [];
		if (!is_array($vcardLines))
		{
			return FieldProcessResult::success();
		}

		$addresses = [];

		foreach ($vcardLines as $vcardLineParts)
		{
			$vcardLine = new VCardLine($vcardLineParts);
			if (!$vcardLine->validate()->isSuccess())
			{
				continue;
			}

			$type = match (true) {
				$vcardLine->isType('HOME') => EntityAddressType::Home,
				$vcardLine->isType('WORK') => EntityAddressType::Work,
				default => EntityAddressType::Primary,
			};

			$pref = $vcardLine->getPref() ?? 0;

			[
				$postOfficeBox,
				$extendedAddress,
				$streetAddress,
				$city,
				$region,
				$postalCode,
				$country,
			] = explode(';', $vcardLine->getValue());

			$address2 = match (true) {
				!empty($postOfficeBox) && !empty($extendedAddress) => "{$postOfficeBox}, {$extendedAddress}",
				!empty($postOfficeBox) => $postOfficeBox,
				!empty($extendedAddress) => $extendedAddress,
				default => '',
			};

			$data = [
				FieldName::ADDRESS_1 => $streetAddress,
				FieldName::ADDRESS_2 => $address2,
				FieldName::CITY => $city,
				FieldName::POSTAL_CODE => $postalCode,
				FieldName::PROVINCE => $region,
				FieldName::COUNTRY => $country,
				FieldName::COUNTRY_CODE => '',
			];

			if (
				!isset($addresses[$type])
				|| $pref > $addresses[$type]['pref']
			)
			{
				$addresses[$type] = [
					'data' => $data,
					'pref' => $pref,
				];
			}
		}

		$resultAddresses = [];
		foreach ($addresses as $type => $addressInfo)
		{
			$resultAddresses[] = new MultipleSaveAddressData(
				addressType: $type,
				addressValues: $addressInfo['data'],
			);
		}

		/** @see MultipleSaveAddress::execute() */
		$importItemFields['ADDRESSES'] = $resultAddresses;

		return FieldProcessResult::success();
	}
}
