<?php

namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Bitrix24\Marketing\Wizard\Constructor\Manager;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\Main\Error;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

class Qualification extends Base
{
	private const FIELD_TYPE_PHONE = 'phone-number';

	private const FIELD_TYPE_SELECT_ONE = 'balloon-one-select';

	private const FIELDS_WHITELIST = [
		'employee-count',
		'business',
		'business-management-system',
		'your-goals',
	];

	/**
	 * @restMethod intranetmobile.qualification.saveFieldValue
	 * @param $value
	 * @param string $id
	 * @param string $type
	 * @return bool|null
	 */
	public function saveFieldValueAction($value, string $id, string $type): ?bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			$this->errorCollection->setError(new Error('Module "bitrix24" is not installed'));

			return null;
		}

		if (!is_array($value))
		{
			return null;
		}

		if ($type === self::FIELD_TYPE_PHONE)
		{
			return $this->savePhoneFieldValue($value, $id);
		}

		if ($type === self::FIELD_TYPE_SELECT_ONE)
		{
			return $this->saveSingleValue($value, $id);
		}

		return null;
	}

	private function saveSingleValue(array $value, string $id): ?bool
	{
		if (!in_array($id, self::FIELDS_WHITELIST, true))
		{
			return null;
		}

		$itemValue = array_values($value)[0];

		if (empty($itemValue))
		{
			return null;
		}

		Option::set(
			Manager::MODULE_CONFIGURATION,
			"cjm-$id",
			Json::encode([
				'itemValue' => $itemValue,
				'context' => 'mobile',
			]),
		);

		return true;
	}

	private function savePhoneFieldValue(array $value, string $id): ?bool
	{
		$phoneNumber = $value['value'] ?? '';
		$countryCode = $value['countryCode'] ?? '';

		if (!$phoneNumber)
		{
			$this->errorCollection->setError(new Error('No phone number provided'));

			return null;
		}

		$phone = new Phone($phoneNumber, $countryCode);
		if (!$phone->isValid())
		{
			$this->errorCollection->setError(new Error('Invalid phone number format'));

			return null;
		}

		Option::set(
			Manager::MODULE_CONFIGURATION,
			"CJM-$id",
			Json::encode([
				'number' => $phone->getRawNumber(),
				'country' => $phone->getCountryCode(),
				'context' => 'mobile',
			]),
		);

		return true;
	}
}
