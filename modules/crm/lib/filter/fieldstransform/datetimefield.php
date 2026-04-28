<?php

namespace Bitrix\Crm\Filter\FieldsTransform;

use Bitrix\Crm\Service\Container;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class DateTimeField
{
	public static function applyTimezoneOffset(int $entityTypeId, array &$filter): void
	{
		$factory = Container::getInstance()->getFactory($entityTypeId);
		if (!$factory)
		{
			return;
		}
		$userFields = $factory->getUserFields() ?? [];
		$fieldsToProcess = [];

		foreach ($userFields as $field)
		{
			if (self::isSuitableField($field))
			{
				$fieldsToProcess[] = $field['FIELD_NAME'];
			}
		}

		if ((method_exists($factory, 'isClientContactEnabled') && $factory->isClientContactEnabled()) || $entityTypeId === \CCrmOwnerType::Deal)
		{
			$contactUserFields = Container::getInstance()->getFactory(\CCrmOwnerType::Contact)->getUserFields();
			foreach ($contactUserFields as $field)
			{
				if (self::isSuitableField($field))
				{
					$fieldsToProcess[] = 'CONTACT_' . $field['FIELD_NAME']; // deal format
					$fieldsToProcess[] = 'CONTACT.' . $field['FIELD_NAME']; // smart process format
				}
			}
		}

		if ((method_exists($factory, 'isClientCompanyEnabled') && $factory->isClientCompanyEnabled()) || $entityTypeId === \CCrmOwnerType::Deal)
		{
			$companyUserFields = Container::getInstance()->getFactory(\CCrmOwnerType::Company)->getUserFields();
			foreach ($companyUserFields as $field)
			{
				if (self::isSuitableField($field))
				{
					$fieldsToProcess[] = 'COMPANY_' . $field['FIELD_NAME']; // deal format
					$fieldsToProcess[] = 'COMPANY.' . $field['FIELD_NAME']; // smart process format
				}
			}
		}

		if (empty($fieldsToProcess))
		{
			return;
		}

		$sqlWhere = new \CSQLWhere();
		foreach ($filter as $filterField => $filterValue)
		{
			$fieldName = $sqlWhere->MakeOperation($filterField)['FIELD'];
			if (in_array($fieldName, $fieldsToProcess))
			{
				try
				{
					$value = DateTime::createFromUserTime($filterValue);
				}
				catch (ObjectException $e)
				{
					continue;
				}
				// apply timezone offset twice:
				// first - into CCrmDateTimeHelper::getUserTime, second - into $value->toString
				$value = \CCrmDateTimeHelper::getUserTime($value);

				$filter[$filterField] = $value->toString();
			}
		}
	}

	private static function isSuitableField(array $field): bool
	{
		return ($field['USER_TYPE_ID'] === 'datetime' && ($field['SETTINGS']['USE_TIMEZONE'] ?? null) === 'N');
	}
}
