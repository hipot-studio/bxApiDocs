<?php

use Bitrix\Crm;

if (!\Bitrix\Main\Loader::includeModule('bizproc'))
{
	return;
}

IncludeModuleLangFile(__DIR__."/crm_document.php");

class CCrmDocumentLead extends CCrmDocument
	implements IBPWorkflowDocument
{
	static public function GetDocumentFields($documentType)
	{
		$arDocumentID = self::GetDocumentInfo($documentType.'_0');
		if (empty($arDocumentID))
			throw new CBPArgumentNullException('documentId');

		$arResult = self::getEntityFields($arDocumentID['TYPE']);

		return $arResult;
	}

	public static function GetDocument($documentId)
	{
		$args = func_get_args();
		$select = ($args[2] ?? []) ?: ['*'];
		$documentInfo = static::GetDocumentInfo($documentId);

		return new Crm\Integration\BizProc\Document\ValueCollection\Lead(
			CCrmOwnerType::Lead,
			$documentInfo['ID'],
			$select
		);
	}

	public static function getEntityFields($entityType)
	{
		$entityTypeId = \CCrmOwnerType::ResolveID($entityType);
		$addressLabels = Crm\EntityAddress::getShortLabels();
		$fullAddressLabel = Crm\EntityAddress::getFullAddressLabel();
		$printableFieldNameSuffix = ' ('.GetMessage('CRM_FIELD_BP_TEXT').')';

		$arResult = static::getVirtualFields() + [
			'ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'ID'),
				'Type' => 'int',
				'Filterable' => true,
				'Editable' => false,
				'Required' => false,
			],
			'TITLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'TITLE'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => true,
			],
			'STATUS_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'STATUS_ID'),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('STATUS'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'STATUS_ID_PRINTABLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'STATUS_ID') . $printableFieldNameSuffix,
				'Type' => 'string',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			],
			'STATUS_DESCRIPTION' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'STATUS_DESCRIPTION'),
				'Type' => 'text',
				'Filterable' => false,
				'Editable' => true,
				'Required' => false,
			],
			"OPENED" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'OPENED'),
				"Type" => "bool",
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
			],
			'OPPORTUNITY' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'OPPORTUNITY'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'CURRENCY_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CURRENCY_ID'),
				'Type' => 'select',
				'Options' => CCrmCurrencyHelper::PrepareListItems(),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ASSIGNED_BY_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'ASSIGNED_BY_ID'),
				'Type' => 'user',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
		];

		$arResult += parent::getAssignedByFields();
		$arResult += [
			'CREATED_BY_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CREATED_BY_ID'),
				'Type' => 'user',
				'Filterable' => true,
				'Editable' => false,
				'Required' => false,
			],
			'CREATED_BY_PRINTABLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CREATED_BY_ID').$printableFieldNameSuffix,
				'Type' => 'string',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			],
			'MODIFY_BY_ID' => [
				'Name' => GetMessage('CRM_DOCUMENT_FIELD_MODIFY_BY_ID'),
				'Type' => 'user',
			],
			'COMMENTS' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'COMMENTS'),
				'Type' => 'text',
				'ValueContentType' => 'bb',
				'Filterable' => false,
				'Editable' => true,
				'Required' => false,
			],
			"OBSERVER_IDS" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'OBSERVER_IDS', GetMessage('CRM_FIELD_OBSERVER_IDS')),
				"Type" => "user",
				"Filterable" => true,
				"Editable" => false,
				"Required" => false,
				"Multiple" => true,
			],
			'NAME' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'NAME'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'LAST_NAME' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'LAST_NAME'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'SECOND_NAME' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'SECOND_NAME'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'HONORIFIC' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'HONORIFIC'),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('HONORIFIC'),
				'Editable' => true,
				'Required' => false,
			],
			'BIRTHDATE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'BIRTHDATE'),
				'Type' => 'datetime',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'EMAIL' => [
				'Name' => \CCrmFieldMulti::GetEntityTypeCaption('EMAIL'),
				'Type' => 'email',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'PHONE' => [
				'Name' => \CCrmFieldMulti::GetEntityTypeCaption('PHONE'),
				'Type' => 'phone',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'WEB' => [
				'Name' => \CCrmFieldMulti::GetEntityTypeCaption('WEB'),
				'Type' => 'web',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'IM' => [
				'Name' => \CCrmFieldMulti::GetEntityTypeCaption('IM'),
				'Type' => 'im',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'COMPANY_TITLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'COMPANY_TITLE'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'POST' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'POST'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'FULL_ADDRESS' => [
				'Name' => $fullAddressLabel,
				'Type' => 'text',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			],
			'ADDRESS' => [
				'Name' => $addressLabels['ADDRESS'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ADDRESS_2' => [
				'Name' => $addressLabels['ADDRESS_2'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ADDRESS_CITY' => [
				'Name' => $addressLabels['CITY'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ADDRESS_POSTAL_CODE' => [
				'Name' => $addressLabels['POSTAL_CODE'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ADDRESS_REGION' => array(
				'Name' => $addressLabels['REGION'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			),
			'ADDRESS_PROVINCE' => [
				'Name' => $addressLabels['PROVINCE'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'ADDRESS_COUNTRY' => [
				'Name' => $addressLabels['COUNTRY'],
				'Type' => 'text',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'SOURCE_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'SOURCE_ID'),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('SOURCE'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'SOURCE_DESCRIPTION' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'SOURCE_DESCRIPTION'),
				'Type' => 'text',
				'Filterable' => false,
				'Editable' => true,
				'Required' => false,
			],
			"DATE_CREATE" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'DATE_CREATE'),
				"Type" => "datetime",
				"Filterable" => true,
				"Editable" => false,
				"Required" => false,
			],
			"DATE_MODIFY" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'DATE_MODIFY'),
				"Type" => "datetime",
				"Filterable" => true,
				"Editable" => false,
				"Required" => false,
			],
			'IS_RETURN_CUSTOMER' => [
				'Name' => GetMessage('CRM_DOCUMENT_LEAD_IS_RETURN_CUSTOMER'),
				'Type' => 'bool',
				'Editable' => false,
			],
			"CONTACT_ID" => [
				"Name" => GetMessage("CRM_DOCUMENT_CRM_ENTITY_TYPE_CONTACT"),
				"Type" => "UF:crm",
				"Options" => ['CONTACT' => 'Y'],
			],
			"CONTACT_IDS" => [
				"Name" => static::getEntityFieldCaption(
					$entityTypeId,
					'CONTACT_IDS',
					GetMessage('CRM_DOCUMENT_FIELD_CONTACT_IDS')
				),
				"Type" => "UF:crm",
				"Options" => ['CONTACT' => 'Y'],
				"Multiple" => true,
			],
			"COMPANY_ID" => [
				"Name" => GetMessage("CRM_DOCUMENT_CRM_ENTITY_TYPE_COMPANY"),
				"Type" => "UF:crm",
				"Options" => ['COMPANY' => 'Y'],
			],
			'TRACKING_SOURCE_ID' => [
				'Name' => static::getEntityFieldCaption(
					$entityTypeId,
					'TRACKING_SOURCE_ID',
					GetMessage('CRM_DOCUMENT_FIELD_TRACKING_SOURCE_ID')
				),
				'Type' => 'select',
				'Options' => array_column(Crm\Tracking\Provider::getActualSources(), 'NAME','ID'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
		];

		if (!class_exists(\Bitrix\Bizproc\BaseType\EntitySelector::class))
		{
			$arResult['WEBFORM_ID'] = [
				'Name' => GetMessage('CRM_DOCUMENT_WEBFORM_ID'),
				'Type' => 'select',
				'Options' => static::getWebFormSelectOptions(),
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			];
		}
		else
		{
			$arResult['WEBFORM_ID'] = [
				'Name' => GetMessage('CRM_DOCUMENT_WEBFORM_ID'),
				'Type' => 'entityselector',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
				'Settings' => [
					'entity' => [
						'id' => 'web_form',
						'dynamicLoad' => true,
						'dynamicSearch' => true,
					],
				],
			];
		}

		$arResult += static::getCommunicationFields();

		$ar =  CCrmFieldMulti::GetEntityTypeList();
		foreach ($ar as $typeId => $arFields)
		{
			$arResult[$typeId.'_PRINTABLE'] = array(
				'Name' => \CCrmFieldMulti::GetEntityTypeCaption($typeId) . $printableFieldNameSuffix,
				'Type' => 'string',
				"Filterable" => true,
				"Editable" => false,
				"Required" => false,
			);
			foreach ($arFields as $valueType => $valueName)
			{
				$arResult[$typeId.'_'.$valueType] = array(
					'Name' => $valueName,
					'Type' => 'string',
					"Filterable" => true,
					"Editable" => false,
					"Required" => false,
				);
				$arResult[$typeId.'_'.$valueType.'_PRINTABLE'] = array(
					'Name' => $valueName.$printableFieldNameSuffix,
					'Type' => 'string',
					"Filterable" => true,
					"Editable" => false,
					"Required" => false,
				);
			}
		}

		global $USER_FIELD_MANAGER;
		$CCrmUserType = new CCrmUserType($USER_FIELD_MANAGER, 'CRM_LEAD');
		$CCrmUserType->AddBPFields($arResult, array('PRINTABLE_SUFFIX' => GetMessage("CRM_FIELD_BP_TEXT")));

		//append UTM fields
		$arResult += parent::getUtmFields();

		//append FORM fields
		$arResult += parent::getSiteFormFields(CCrmOwnerType::Lead);

		return $arResult;
	}

	/**
	 * @deprecated
	 * @see Crm\Integration\BizProc\Document\ValueCollection\Deal
	 */
	public static function PrepareDocument(array &$arFields)
	{
	}

	public static function CreateDocument($parentDocumentId, $arFields)
	{
		if(!is_array($arFields))
		{
			throw new Exception("Entity fields must be array");
		}

		global $DB;
		$arDocumentID = self::GetDocumentInfo($parentDocumentId);
		if ($arDocumentID == false)
		{
			$arDocumentID['TYPE'] = $parentDocumentId;
		}

		$arDocumentFields = self::GetDocumentFields($arDocumentID['TYPE']);

		$arKeys = array_keys($arFields);
		foreach ($arKeys as $key)
		{
			if (!array_key_exists($key, $arDocumentFields))
			{
				//Fix for issue #40374
				unset($arFields[$key]);
				continue;
			}

			$fieldType = $arDocumentFields[$key]["Type"];
			if (in_array($fieldType, array("phone", "email", "im", "web"), true))
			{
				CCrmDocument::PrepareEntityMultiFields($arFields, mb_strtoupper($fieldType));
				continue;
			}

			$arFields[$key] = (is_array($arFields[$key]) && !CBPHelper::IsAssociativeArray($arFields[$key])) ? $arFields[$key] : array($arFields[$key]);
			if ($fieldType == "user")
			{
				$arFields[$key] = \CBPHelper::extractUsers($arFields[$key], $arDocumentID['DOCUMENT_TYPE']);
			}
			elseif ($fieldType == "select" && mb_substr($key, 0, 3) == "UF_")
			{
				self::InternalizeEnumerationField('CRM_LEAD', $arFields, $key);
			}
			elseif ($fieldType == "file")
			{
				$arFileOptions = array('ENABLE_ID' => true);
				foreach ($arFields[$key] as &$value)
				{
					//Issue #40380. Secure URLs and file IDs are allowed.
					$file = false;
					CCrmFileProxy::TryResolveFile($value, $file, $arFileOptions);
					$value = $file;
				}
				unset($value);
			}
			elseif ($fieldType == "S:HTML")
			{
				foreach ($arFields[$key] as &$value)
				{
					$value = array("VALUE" => $value);
				}
				unset($value);
			}

			if (!$arDocumentFields[$key]["Multiple"] && is_array($arFields[$key]))
			{
				if (count($arFields[$key]) > 0)
				{
					$a = array_values($arFields[$key]);
					$arFields[$key] = $a[0];
				}
				else
				{
					$arFields[$key] = null;
				}
			}
		}

		$useTransaction = static::shouldUseTransaction();

		if ($useTransaction)
		{
			$DB->StartTransaction();
		}

		if(isset($arFields['COMMENTS']))
		{
			$arFields['COMMENTS'] = static::sanitizeCommentsValue($arFields['COMMENTS']);
		}

		$CCrmEntity = new CCrmLead(false);
		$id = $CCrmEntity->Add(
			$arFields,
			true,
			[
				'DISABLE_USER_FIELD_CHECK' => true,
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => static::getSystemUserId(),
			]
		);

		if (!$id || $id <= 0)
		{
			if ($useTransaction)
			{
				$DB->Rollback();
			}
			throw new Exception($CCrmEntity->LAST_ERROR);
		}

		if (isset($arFields['TRACKING_SOURCE_ID']))
		{
			Crm\Tracking\UI\Details::saveEntityData(\CCrmOwnerType::Lead, $id, $arFields);
		}

		$starter = new Crm\Integration\BizProc\Starter\CrmStarter(
			new Crm\Integration\BizProc\Starter\Dto\DocumentDto(\CCrmOwnerType::Lead, (int)$id)
		);
		$result = $starter
			->setContextModuleId('bizproc')
			->runOnInnerDocumentAdd(
				new Crm\Integration\BizProc\Starter\Dto\RunDataDto(
					actualFields: $arFields,
				)
			)
		;

		if ($useTransaction)
		{
			if ($result->isSuccess())
			{
				$DB->Commit();
			}
			else
			{
				$DB->Rollback();

				throw new Exception(CBPHelper::stringify($result->getErrorMessages()));
			}
		}

		return $id;
	}

	public static function UpdateDocument($documentId, $arFields, $modifiedById = null)
	{
		global $DB;

		if(empty($arFields))
		{
			return;
		}

		$arDocumentID = self::GetDocumentInfo($documentId);
		if (empty($arDocumentID))
			throw new CBPArgumentNullException('documentId');

		if(!CCrmLead::Exists($arDocumentID['ID']))
		{
			throw new Exception(GetMessage('CRM_DOCUMENT_ELEMENT_IS_NOT_FOUND'));
		}

		$complexDocumentId = [$arDocumentID['DOCUMENT_TYPE'][0], $arDocumentID['DOCUMENT_TYPE'][1], $documentId];
		$arDocumentFields = self::GetDocumentFields($arDocumentID['TYPE']);

		$arKeys = array_keys($arFields);
		foreach ($arKeys as $key)
		{
			if (!array_key_exists($key, $arDocumentFields))
			{
				//Fix for issue #40374
				unset($arFields[$key]);
				continue;
			}

			$fieldType = $arDocumentFields[$key]["Type"];
			if (in_array($fieldType, array("phone", "email", "im", "web"), true))
			{
				CCrmDocument::PrepareEntityMultiFields($arFields, mb_strtoupper($fieldType));
				continue;
			}

			$arFields[$key] = (is_array($arFields[$key]) && !CBPHelper::IsAssociativeArray($arFields[$key])) ? $arFields[$key] : array($arFields[$key]);
			if ($fieldType == "user")
			{
				$arFields[$key] = \CBPHelper::extractUsers($arFields[$key], $complexDocumentId);
			}
			elseif ($fieldType == "select" && mb_substr($key, 0, 3) == "UF_")
			{
				self::InternalizeEnumerationField('CRM_LEAD', $arFields, $key);
			}
			elseif ($fieldType == "file")
			{
				$arFields[$key] = static::castFileFieldValues(
					$arDocumentID['ID'],
					\CCrmOwnerType::Lead,
					$key,
					$arFields[$key],
				);
			}
			elseif ($fieldType == "S:HTML")
			{
				foreach ($arFields[$key] as &$value)
				{
					$value = array("VALUE" => $value);
				}
				unset($value);
			}

			if (!$arDocumentFields[$key]["Multiple"] && is_array($arFields[$key]))
			{
				if (count($arFields[$key]) > 0)
				{
					$a = array_values($arFields[$key]);
					$arFields[$key] = $a[0];
				}
				else
				{
					$arFields[$key] = null;
				}
			}
		}

		if(isset($arFields['COMMENTS']) && $arFields['COMMENTS'] !== '')
		{
			$arFields['COMMENTS'] = static::sanitizeCommentsValue($arFields['COMMENTS']);
		}

		//check STATUS_ID changes for automation
		$arPresentFields = [];
		if (isset($arFields['STATUS_ID']))
		{
			$dbDocumentList = CCrmLead::GetListEx(
				array(),
				array('ID' => $arDocumentID['ID'], 'CHECK_PERMISSIONS' => 'N'),
				false,
				false,
				array('ID', 'STATUS_ID')
			);
			$arPresentFields = $dbDocumentList->Fetch();
		}

		$useTransaction = static::shouldUseTransaction();

		if ($useTransaction)
		{
			$DB->StartTransaction();
		}

		if ($modifiedById > 0)
		{
			$arFields['MODIFY_BY_ID'] = $modifiedById;
		}

		$updatedFields = $arFields;

		$CCrmEntity = new CCrmLead(false);
		$res = $CCrmEntity->Update(
			$arDocumentID['ID'],
			$arFields,
			true,
			true,
			[
				'DISABLE_USER_FIELD_CHECK' => true,
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => $modifiedById ?? static::getSystemUserId(),
			]
		);

		if (!$res)
		{
			if ($useTransaction)
			{
				$DB->Rollback();
			}
			throw new Exception($CCrmEntity->LAST_ERROR);
		}

		if (isset($arFields['TRACKING_SOURCE_ID']))
		{
			Crm\Tracking\UI\Details::saveEntityData(
				\CCrmOwnerType::Lead,
				$arDocumentID['ID'],
				$arFields
			);
		}

		$starter = new Crm\Integration\BizProc\Starter\CrmStarter(
			new Crm\Integration\BizProc\Starter\Dto\DocumentDto(\CCrmOwnerType::Lead, (int)$arDocumentID['ID'])
		);
		$result = $starter
			->setContextModuleId('bizproc')
			->runOnInnerDocumentUpdate(
				new Crm\Integration\BizProc\Starter\Dto\RunDataDto(
					actualFields: $updatedFields,
					previousFields: $arPresentFields,
				)
			)
		;

		if ($useTransaction)
		{
			if ($result->isSuccess())
			{
				$DB->Commit();
			}
			else
			{
				$DB->Rollback();

				throw new Exception(CBPHelper::stringify($result->getErrorMessages()));
			}
		}
	}

	public static function getDocumentName($documentId)
	{
		$arDocumentID = self::GetDocumentInfo($documentId);
		$caption = '';

		$dbRes = CCrmLead::GetListEx([], ['=ID' => $arDocumentID['ID'], 'CHECK_PERMISSIONS' => 'N'],
			false, false,
			['TITLE', 'HONORIFIC', 'NAME', 'SECOND_NAME', 'LAST_NAME']
		);
		$arRes = $dbRes ? $dbRes->Fetch() : null;

		if ($arRes)
		{
			$caption = isset($arRes['TITLE']) ? $arRes['TITLE'] : '';
			if ($caption === '')
			{
				$caption = CCrmLead::PrepareFormattedName(
					array(
						'HONORIFIC'   => isset($arRes['HONORIFIC']) ? $arRes['HONORIFIC'] : '',
						'NAME'        => isset($arRes['NAME']) ? $arRes['NAME'] : '',
						'SECOND_NAME' => isset($arRes['SECOND_NAME']) ? $arRes['SECOND_NAME'] : '',
						'LAST_NAME'   => isset($arRes['LAST_NAME']) ? $arRes['LAST_NAME'] : ''
					)
				);
			}
		}

		return $caption;
	}

	public static function normalizeDocumentId($documentId)
	{
		return parent::normalizeDocumentIdInternal(
			$documentId,
			CCrmOwnerType::LeadName,
			CCrmOwnerTypeAbbr::Lead
		);
	}

	public static function createAutomationTarget($documentType, string|int|null $documentId = null)
	{
		if (is_string($documentId))
		{
			[$entityTypeId, $entityId] = CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);
			if ($entityId > 0 && $entityTypeId === CCrmOwnerType::Lead)
			{
				return Crm\Automation\Factory::getTarget(CCrmOwnerType::Lead, $entityId);
			}
		}

		return Crm\Automation\Factory::createTarget(\CCrmOwnerType::Lead);
	}
}
