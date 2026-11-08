<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\BIConnector\MemoryCache;
use Bitrix\BIConnector\PrettyPrinter;
use Bitrix\BIConnector\Superset\Config\DatasetSettings;
use Bitrix\BIConnector\UserField\ProxyUserFieldManager;
use Bitrix\Crm\RequisiteTable;
use Bitrix\Main\Application;
use Bitrix\Main\UserField\Types\BooleanType;

final class RequisiteUserFieldsMapping
{
	public static function getMapping(string $languageId): ?array
	{
		$userFields = Application::getUserTypeManager()->getUserFields(
			RequisiteTable::getUfId(),
			0,
			$languageId,
		);
		if (!$userFields)
		{
			return null;
		}

		$typingEnabled = DatasetSettings::isTypingEnabled();

		return self::buildMapping($languageId, $userFields, $typingEnabled);
	}

	public static function buildMapping(string $languageId, array $userFields, bool $typingEnabled): ?array
	{
		if (!$userFields)
		{
			return null;
		}

		$mapping = [
			'TABLE_NAME' => 'b_uts_crm_requisite',
			'TABLE_ALIAS' => 'RUF',
			'TABLE_DESCRIPTION' => Localization::getMessage('CRM_REQUISITE_UF_TABLE', $languageId)
				?: 'crm_requisite_uf',
			'TABLE_DESCRIPTION_FULL' => Localization::getMessage(
				'CRM_REQUISITE_UF_TABLE_DESCRIPTION_FULL',
				$languageId,
			) ?? '',
			'FIELDS' => [
				'REQUISITE_ID' => [
					'IS_PRIMARY' => 'Y',
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'RUF.VALUE_ID',
					'FIELD_TYPE' => 'int',
					'FIELD_DESCRIPTION' => Localization::getMessage(
						'CRM_REQUISITE_UF_FIELD_REQUISITE_ID',
						$languageId,
					) ?: 'REQUISITE_ID',
				],
				'DATE_CREATE' => [
					'IS_METRIC' => 'N',
					'FIELD_NAME' => 'R.DATE_CREATE',
					'FIELD_TYPE' => 'datetime',
					'TABLE_ALIAS' => 'R',
					'JOIN' => 'INNER JOIN b_crm_requisite R ON R.ID = RUF.VALUE_ID',
					'LEFT_JOIN' => 'LEFT JOIN b_crm_requisite R ON R.ID = RUF.VALUE_ID',
					'FIELD_DESCRIPTION' => Localization::getMessage(
						'CRM_REQUISITE_UF_FIELD_DATE_CREATE',
						$languageId,
					) ?: 'DATE_CREATE',
				],
			],
		];

		foreach ($userFields as $userField)
		{
			$code = $userField['FIELD_NAME'];
			$mapping['FIELDS'][$code] = [
				'FIELD_DESCRIPTION' => $userField['EDIT_FORM_LABEL'] ?: $code,
				'IS_METRIC' => 'N',
				'FIELD_NAME' => 'RUF.' . $code,
				'FIELD_TYPE' => $userField['MULTIPLE'] === 'N' && $typingEnabled
					? $userField['USER_TYPE_ID']
					: 'string',
				'CALLBACK' => self::getValueCallback($userField, $typingEnabled),
			];
		}

		return $mapping;
	}

	private static function getValueCallback(array $userField, bool $typingEnabled): \Closure
	{
		return static function ($value, array $dateFormats) use ($userField, $typingEnabled) {
			if (in_array($userField['USER_TYPE_ID'], ['date', 'datetime'], true))
			{
				if ($value === null || $value === '')
				{
					return null;
				}

				$format = $userField['USER_TYPE_ID'] === 'date'
					? $dateFormats['date_format_php']
					: $dateFormats['datetime_format_php'];

				return PrettyPrinter::formatUserFieldAsDate($userField, $value, $format);
			}

			if (
				$userField['USER_TYPE_ID'] === BooleanType::USER_TYPE_ID
				&& $userField['MULTIPLE'] === 'N'
				&& $typingEnabled
			)
			{
				return (bool)$value;
			}

			$cacheKey = serialize($value);
			$cachedResult = MemoryCache::get($userField['ID'], $cacheKey);
			if (isset($cachedResult))
			{
				return $cachedResult;
			}

			$userFieldManager = Application::getUserTypeManager();
			if ($userField['MULTIPLE'] === 'Y')
			{
				$values = $userFieldManager->onAfterFetch(
					$userField,
					$value !== null
						? unserialize($value, ['allowed_classes' => PrettyPrinter::$allowedUnserializeClassesList])
						: false,
				);
			}
			else
			{
				$values = [$userFieldManager->onAfterFetch($userField, $value)];
			}

			$localUserField = $userField;
			$localUserField['VALUE'] = $values;

			$result = ProxyUserFieldManager::getText($localUserField);
			MemoryCache::set($userField['ID'], $cacheKey, $result);

			return $result;
		};
	}
}
