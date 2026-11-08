<?php

namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Bitrix24\CurrentUser;
use Bitrix\Bitrix24\Marketing\Wizard\Constructor\Fields\BaseField;
use Bitrix\Bitrix24\Marketing\Wizard\Constructor\Manager;
use Bitrix\Intranet\Entity\Type\Phone;
use Bitrix\IntranetMobile\ActionFilter\MobileQualificationPresetAccess;
use Bitrix\IntranetMobile\Qualification\MobilePresetApplier;
use Bitrix\Main\Error;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\EnablePrefilters;
use Bitrix\Main\Engine\Response\AjaxJson;
use Bitrix\Main\Loader;
use Bitrix\Main\Response;
use Bitrix\Main\Web\Json;

class Qualification extends Base
{
	private const FIELD_TYPE_PHONE = 'phone-number';

	private const FIELD_TYPE_SELECT_ONE = 'balloon-one-select';

	private const FIELD_TYPE_SELECT_MULTIPLE = 'balloon-multiple-select';

	#[EnablePrefilters([
		new MobileQualificationPresetAccess(),
	])]
	public function applyMobilePresetAction(string $presetId): Response
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		$result = (new MobilePresetApplier())->apply(
			$presetId,
			$userId,
			(string)Application::getInstance()->getContext()->getSite(),
		);

		if (!$result->isSuccess())
		{
			return AjaxJson::createError($result->getErrorCollection());
		}

		return AjaxJson::createSuccess($result->getData());
	}

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

		if (!CurrentUser::get()->isAdmin())
		{
			return null;
		}

		if (!is_array($value))
		{
			return null;
		}

		$field = $this->resolveField($id, $type);
		if ($field === null)
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

		if ($type === self::FIELD_TYPE_SELECT_MULTIPLE)
		{
			return $this->saveMultipleValue($value, $id, $field);
		}

		return null;
	}

	private function resolveField(string $id, string $type): ?BaseField
	{
		foreach (Manager::createMobileManager()->getSteps() as $step)
		{
			foreach ($step->getFields() as $field)
			{
				if ($field->getId() === $id && $field->getType() === $type)
				{
					return $field;
				}
			}
		}

		return null;
	}

	private function saveSingleValue(array $value, string $id): ?bool
	{
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

	private function saveMultipleValue(array $value, string $id, BaseField $field): ?bool
	{
		$items = [];

		foreach ($field->export()['data'] ?? [] as $option)
		{
			if (!is_array($option) || !isset($option['id']) || !is_string($option['id']))
			{
				continue;
			}

			if (!array_key_exists($option['id'], $value))
			{
				continue;
			}

			$items[] = [
				'value' => $option['value'] ?? '',
				'title' => $option['title'] ?? '',
			];
		}

		if (empty($items))
		{
			return null;
		}

		Option::set(
			Manager::MODULE_CONFIGURATION,
			"cjm-$id",
			Json::encode([
				'itemValue' => $items,
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
