<?php

namespace Bitrix\Lists;

use Bitrix\Iblock;
use Bitrix\Iblock\PropertyEnumerationTable;
use Bitrix\Iblock\PropertyTable;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

if (!Loader::includeModule('bizproc'))
{
	return;
}

class BizprocDocumentLists extends \BizprocDocument
{
	public static function getEntityName()
	{
		return Loc::getMessage('LISTS_BIZPROC_ENTITY_LISTS_NAME');
	}

	/**
	 * @param string $documentType
	 * @return array
	 * @throws \CBPArgumentOutOfRangeException
	 */
	public static function getDocumentFields($documentType)
	{
		$documentType = (string)$documentType;
		if ($documentType === '' || !str_starts_with($documentType, 'iblock_'))
		{
			throw new \CBPArgumentOutOfRangeException('documentType', $documentType);
		}
		$iblockId = (int)(substr($documentType, 7)); // length 'iblock_' - 7
		if ($iblockId <= 0)
		{
			throw new \CBPArgumentOutOfRangeException('documentType', $documentType);
		}

		$documentFieldTypes = self::getDocumentFieldTypes($documentType);

		$result = self::getSystemIblockFields();

		$employeeNotCompatible = Option::get('bizproc', 'employee_compatible_mode', 'N') !== 'Y';

		$enumSelect = [
			'VALUE',
			'INDEX' => (self::GetVersion() > 1 ? 'XML_ID' : 'ID'),
		];
		$propertyObject = Iblock\PropertyTable::getList([
			'select' => ['*'],
			'filter' => [
				'=IBLOCK_ID' => $iblockId,
				'=ACTIVE' => 'Y',
			],
			'order' => [
				'SORT' => 'ASC',
				'NAME' => 'ASC',
			],
			'cache' => [
				'ttl' => 86400,
			],
		]);
		Iblock\PropertyTable::fillOldCoreFetchModifiers($propertyObject);

		$ignoreProperty = array();
		while ($property = $propertyObject->fetch())
		{
			$property['CODE'] = (string)$property['CODE'];
			$property['USER_TYPE'] = (string)$property['USER_TYPE'];
			$propertyIdAlias = 'PROPERTY_' . $property['ID'];

			if ($property['CODE'])
			{
				$key = 'PROPERTY_' . $property['CODE'];
				$ignoreProperty[$propertyIdAlias] = $key;
			}
			else
			{
				$key = $propertyIdAlias;
				$ignoreProperty[$propertyIdAlias] = 0;
			}

			$result[$key] = [
				"Name" => $property["NAME"],
				"Filterable" => ($property["FILTRABLE"] == "Y"),
				"Editable" => true,
				"Required" => ($property["IS_REQUIRED"] == "Y"),
				"Multiple" => ($property["MULTIPLE"] == "Y"),
				"TypeReal" => $property["PROPERTY_TYPE"],
				"UserTypeSettings" => $property["USER_TYPE_SETTINGS"],
				'IblockPropertyId' => (int)$property['ID'],
			];

			if ($property['CODE'])
			{
				$result[$key]['Alias'] = $propertyIdAlias;
			}
			unset(
				$propertyIdAlias,
			);

			if ($property["USER_TYPE"] !== '')
			{
				$result[$key]["TypeReal"] = $property["PROPERTY_TYPE"].":".$property["USER_TYPE"];

				if (
					$property["USER_TYPE"] === PropertyTable::USER_TYPE_USER
					|| (
						$property["USER_TYPE"] === PropertyTable::USER_TYPE_EMPLOYEE && $employeeNotCompatible
					)
				)
				{
					$result[$key]["Type"] = "user";
					$result[$key."_PRINTABLE"] = array(
						"Name" => $property["NAME"].GetMessage("IBD_FIELD_USERNAME_PROPERTY"),
						"Filterable" => false,
						"Editable" => false,
						"Required" => false,
						"Multiple" => ($property["MULTIPLE"] == "Y"),
						"Type" => "string",
					);
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_DATETIME)
				{
					$result[$key]["Type"] = "datetime";
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_DATE)
				{
					$result[$key]["Type"] = "date";
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_ELEMENT_LIST)
				{
					$result[$key]["Type"] = "E:EList";
					$result[$key]["Options"] = $property["LINK_IBLOCK_ID"];
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_CRM)
				{
					$result[$key]["Type"] = "E:ECrm";
					$result[$key]["DefaultValue"] = $property["DEFAULT_VALUE"];
					$result[$key]["Options"] = $property["USER_TYPE_SETTINGS"];
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_MONEY)
				{
					$result[$key]["Type"] = "S:Money";
					$result[$key]["DefaultValue"] = $property["DEFAULT_VALUE"];
					$result[$key."_PRINTABLE"] = array(
						"Name" => $property["NAME"].GetMessage("IBD_FIELD_USERNAME_PROPERTY"),
						"Filterable" => false,
						"Editable" => false,
						"Required" => false,
						"Multiple" => ($property["MULTIPLE"] == "Y"),
						"Type" => "string",
					);
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_SEQUENCE)
				{
					$result[$key]["Type"] = "N:Sequence";
					$result[$key]["DefaultValue"] = $property["DEFAULT_VALUE"];
					$result[$key]["Options"] = $property["USER_TYPE_SETTINGS"];
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_DISK)
				{
					$result[$key]["Type"] = "S:DiskFile";
					$result[$key."_PRINTABLE"] = array(
						"Name" => $property["NAME"].GetMessage("IBD_FIELD_USERNAME_PROPERTY"),
						"Filterable" => false,
						"Editable" => false,
						"Required" => false,
						"Multiple" => ($property["MULTIPLE"] == "Y"),
						"Type" => "int",
					);
				}
				elseif ($property["USER_TYPE"] === PropertyTable::USER_TYPE_HTML)
				{
					$result[$key]["Type"] = "S:HTML";
				}
				else
				{
					$result[$key]["Type"] = "string";
				}
			}
			elseif ($property["PROPERTY_TYPE"] === PropertyTable::TYPE_LIST)
			{
				$result[$key]["Type"] = "select";

				$result[$key]["Options"] = [];

				$enumIterator = PropertyEnumerationTable::getList([
					'select' => $enumSelect,
					'filter' => [
						'=PROPERTY_ID' => (int)$property['ID'],
					],
					'cache' => [
						'ttl' => 86400,
					],
				]);
				while ($enumRow = $enumIterator->fetch())
				{
					$result[$key]["Options"][htmlspecialcharsEx($enumRow['INDEX'])] = $enumRow['VALUE'];
				}
				unset(
					$enumRow,
					$enumIterator,
				);
			}
			elseif ($property["PROPERTY_TYPE"] === PropertyTable::TYPE_NUMBER)
			{
				$result[$key]["Type"] = "double";
			}
			elseif ($property["PROPERTY_TYPE"] === PropertyTable::TYPE_FILE)
			{
				$result[$key]["Type"] = "file";
				$result[$key."_PRINTABLE"] = array(
					"Name" => $property["NAME"].GetMessage("IBD_FIELD_USERNAME_PROPERTY"),
					"Filterable" => false,
					"Editable" => false,
					"Required" => false,
					"Multiple" => ($property["MULTIPLE"] == "Y"),
					"Type" => "string",
				);
			}
			elseif ($property["PROPERTY_TYPE"] === PropertyTable::TYPE_STRING)
			{
				$result[$key]["Type"] = "string";
			}
			elseif ($property["PROPERTY_TYPE"] === PropertyTable::TYPE_ELEMENT)
			{
				$result[$key]["Type"] = "E:EList";
				$result[$key]["Options"] = $property["LINK_IBLOCK_ID"];
				$result[$key]["DefaultValue"] = $property["DEFAULT_VALUE"];
			}
			else
			{
				$result[$key]["Type"] = "string";
			}
		}
		unset(
			$property,
			$propertyObject,
		);

		$list = new \CList($iblockId);
		$fields = $list->getFields();
		foreach($fields as $fieldId => $field)
		{
			if(empty($field["SETTINGS"]))
				$field["SETTINGS"] = array("SHOW_ADD_FORM" => 'Y', "SHOW_EDIT_FORM"=>'Y');

			if (isset($ignoreProperty[$fieldId]))
			{
				$ignoreProperty[$fieldId] ? $key = $ignoreProperty[$fieldId] : $key = $fieldId;
				$result[$key]["sort"] =  $field["SORT"];
				$result[$key]["settings"] = $field["SETTINGS"];
				$result[$key]["active"] = true;
				$result[$key]["DefaultValue"] = $field["DEFAULT_VALUE"];
				if (isset($field['ROW_COUNT'], $field['COL_COUNT']) && $field['ROW_COUNT'] && $field['COL_COUNT'])
				{
					$result[$key]["row_count"] = $field["ROW_COUNT"];
					$result[$key]["col_count"] = $field["COL_COUNT"];
				}
			}
			else
			{
				$result[$fieldId] = array(
					"Name" => $field['NAME'],
					"Filterable" => !empty($result[$fieldId]['Filterable']) ? $result[$fieldId]['Filterable'] : false,
					"Editable" => !empty($result[$fieldId]['Editable']) ? $result[$fieldId]['Editable'] : true,
					"Required" => ($field['IS_REQUIRED'] == 'Y'),
					"Multiple" => ($field['MULTIPLE'] == 'Y'),
					"Type" => !empty($result[$fieldId]['Type']) ? $result[$fieldId]['Type'] : $field['TYPE'],
					"sort" => $field["SORT"],
					"settings" => $field["SETTINGS"],
					"active" => true,
					"active_type" => $field['TYPE'],
					"DefaultValue" => $field["DEFAULT_VALUE"],
				);
				if (isset($field['ROW_COUNT'], $field['COL_COUNT']) && $field['ROW_COUNT'] && $field['COL_COUNT'])
				{
					$result[$fieldId]["row_count"] = $field["ROW_COUNT"];
					$result[$fieldId]["col_count"] = $field["COL_COUNT"];
				}
			}
		}

		$keys = array_keys($result);
		foreach ($keys as $k)
		{
			$result[$k]["BaseType"] = $documentFieldTypes[$result[$k]["Type"]]["BaseType"] ?? null;
			$result[$k]["Complex"] = $documentFieldTypes[$result[$k]["Type"]]["Complex"] ?? null;
		}

		return $result;
	}

	public static function isFeatureEnabled($documentType, $feature)
	{
		return in_array($feature, array(\CBPDocumentService::FEATURE_MARK_MODIFIED_FIELDS));
	}

	protected static function compileDocumentUrl(array $element, int $documentId): mixed
	{
		foreach(GetModuleEvents('iblock', 'CIBlockDocument_OnGetDocumentAdminPage', true) as $arEvent)
		{
			$url = ExecuteModuleEventEx($arEvent, array($element));
			if ($url)
			{
				return $url;
			}
		}

		if ($element['IBLOCK_TYPE_ID'] === 'lists')
		{
			if (ModuleManager::isModuleInstalled('bitrix24'))
			{
				return sprintf('/company/lists/%u/element/0/%u/', $element['IBLOCK_ID'], $element['ID']);
			}

			return sprintf('/services/lists/%u/element/0/%u/', $element['IBLOCK_ID'], $element['ID']);
		}

		return (
			'/bitrix/admin/iblock_element_edit.php?view=Y&ID='
			. $documentId
			. '&IBLOCK_ID='
			. $element['IBLOCK_ID']
			. '&type='
			. $element['IBLOCK_TYPE_ID']
		);
	}
}
