<?php
namespace Bitrix\BIConnector\Integration\Crm;

use Bitrix\BIConnector\Integration\Crm\ProductProperty\CatalogIblock;
use Bitrix\BIConnector\Integration\Crm\ProductProperty\ValueDatasetDescription;
use Bitrix\Main\Localization\Loc;

class ProductPropertyValue
{
	/**
	 *
	 * @param \Bitrix\Main\Event $event Event data.
	 *
	 * @return void
	 */
	public static function onBIConnectorDataSources(\Bitrix\Main\Event $event)
	{
		if (
			!\Bitrix\Main\Loader::includeModule('crm')
			|| !\Bitrix\Main\Loader::includeModule('catalog')
		)
		{
			return;
		}

		$params = $event->getParameters();
		$manager = $params[0];
		$result = &$params[1];
		$languageId = $params[2];

		$eventTableName = $params[3];
		if (!empty($eventTableName) && $eventTableName !== 'crm_product_property_value')
		{
			return;
		}

		$crmCatalogIblockId = \Bitrix\Crm\Product\Catalog::getDefaultId();
		$crmCatalogIblockOfferId = \Bitrix\Crm\Product\Catalog::getDefaultOfferId();
		if (!$crmCatalogIblockId)
		{
			return;
		}

		$catalog = new CatalogIblock($crmCatalogIblockId, self::getIblockVersion($crmCatalogIblockId));
		$offers =
			$crmCatalogIblockOfferId === null
				? null
				: new CatalogIblock($crmCatalogIblockOfferId, self::getIblockVersion($crmCatalogIblockOfferId))
		;

		$connection = $manager->getDatabaseConnection();
		/** @var \Bitrix\Main\DB\SqlHelper&\Bitrix\BIConnector\DB\BiSqlHelperInterface $helper */
		$helper = $connection->getSqlHelper();

		$description = ValueDatasetDescription::build(
			$helper,
			$catalog,
			$offers,
			ValueDatasetDescription::loadProperties(
				$catalog->id,
				...($offers !== null ? [$offers->id] : []),
			),
		);
		if (!$description)
		{
			return;
		}

		$result['crm_product_property_value'] = $description;

		$messages = Loc::loadLanguageFile(__FILE__, $languageId);
		$result['crm_product_property_value']['TABLE_DESCRIPTION'] = $messages['CRM_BIC_PRODUCT_PROPERTY_VALUE_TABLE'] ?? 'crm_product_property_value';
		$result['crm_product_property_value']['TABLE_DESCRIPTION_FULL'] = $messages['CRM_BIC_PRODUCT_PROPERTY_VALUE_TABLE_DESCRIPTION_FULL_MSGVER_1'] ?? '';
		foreach ($result['crm_product_property_value']['FIELDS'] as $fieldCode => &$fieldInfo)
		{
			$fieldInfo['FIELD_DESCRIPTION'] = !empty($messages['CRM_BIC_PRODUCT_PROPERTY_VALUE_FIELD_' . $fieldCode]) ? $messages['CRM_BIC_PRODUCT_PROPERTY_VALUE_FIELD_' . $fieldCode] : null;
			if (!$fieldInfo['FIELD_DESCRIPTION'])
			{
				$fieldInfo['FIELD_DESCRIPTION'] =
					$messages['CRM_BIC_PRODUCT_PROPERTY_VALUE_FIELD_' . $fieldCode . '_MSGVER_1']
					?? $fieldCode
				;
			}

			$fieldInfo['FIELD_DESCRIPTION_FULL'] = self::getFieldDescriptionFull($messages, $fieldCode);
		}
		unset($fieldInfo);
	}

	/**
	 * Version of the storage of an iblock of the CRM catalog.
	 *
	 * @param int $iblockId Iblock of the CRM catalog.
	 *
	 * @return int Version 1 for an iblock the portal no longer has.
	 */
	private static function getIblockVersion(int $iblockId): int
	{
		$iblock = \Bitrix\Iblock\IblockTable::getRow([
			'select' => ['VERSION'],
			'filter' => [
				'=ID' => $iblockId,
			],
		]);

		return $iblock && $iblock['VERSION'] ? (int)$iblock['VERSION'] : 1;
	}

	/**
	 * Full description of a field: the key of the last version first, the base key next.
	 *
	 * @param array<string, string> $messages Messages of the language file of the dataset.
	 * @param string $fieldCode Code of the field of the dataset.
	 *
	 * @return string Empty string when the field has no full description.
	 */
	private static function getFieldDescriptionFull(array $messages, string $fieldCode): string
	{
		$baseKey = 'CRM_BIC_PRODUCT_PROPERTY_VALUE_FIELD_' . $fieldCode . '_FULL';

		foreach ([$baseKey . '_MSGVER_1', $baseKey] as $key)
		{
			if (!empty($messages[$key]))
			{
				return $messages[$key];
			}
		}

		return '';
	}
}
