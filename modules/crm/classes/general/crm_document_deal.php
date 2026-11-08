<?php

use Bitrix\Crm;
use Bitrix\Crm\V2\Internal\Integration\Bizproc\WorkflowDocument;
use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\Integration\BizProc\Starter\CrmStarter;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\DocumentDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;

if (!CModule::IncludeModule('bizproc'))
{
	return;
}

IncludeModuleLangFile(__DIR__ . '/crm_document.php');

class CCrmDocumentDeal extends CCrmDocument implements IBPWorkflowDocument
{
	static public function GetDocumentFields($documentType)
	{
		$arDocumentID = self::GetDocumentInfo($documentType . '_0');
		if (empty($arDocumentID))
		{
			throw new CBPArgumentNullException('documentId');
		}

		$arResult = self::getEntityFields($arDocumentID['TYPE']);

		self::appendReferenceFields(
			$arResult,
			\CCrmDocumentContact::getEntityFields(\CCrmOwnerType::ContactName),
			\CCrmOwnerType::Contact
		);

		self::appendReferenceFields(
			$arResult,
			\CCrmDocumentCompany::getEntityFields(\CCrmOwnerType::CompanyName),
			\CCrmOwnerType::Company
		);

		return $arResult;
	}

	private static function appendReferenceFields(array &$thisFields, array $referenceFields, $entityTypeId)
	{
		$fieldNamePrefix = \CCrmOwnerType::GetDescription($entityTypeId) . ': ';
		$fieldIdPrefix = \CCrmOwnerType::ResolveName($entityTypeId);

		foreach ($referenceFields as $id => $field)
		{
			if (mb_strpos($id, '.') !== false)
			{
				continue;
			}

			$field['Filterable'] = $field['Editable'] = false;
			$field['Name'] = $fieldNamePrefix.$field['Name'];
			$thisFields[$fieldIdPrefix.'.'.$id] = $field;
		}
	}

	public static function GetDocument($documentId)
	{
		$args = func_get_args();
		$select = ($args[2] ?? []) ?: ['*'];
		$documentInfo = static::GetDocumentInfo($documentId);

		return new Crm\Integration\BizProc\Document\ValueCollection\Deal(
			CCrmOwnerType::Deal,
			$documentInfo['ID'],
			$select
		);
	}

	public static function getEntityFields($entityType)
	{
		$entityTypeId = \CCrmOwnerType::ResolveID($entityType);
		$printableFieldNameSuffix = ' (' . GetMessage('CRM_FIELD_BP_TEXT') . ')';

		$arResult = static::getVirtualFields() + [
			'ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'ID'),
				'Type' => 'int',
				'Filterable' => true,
				'Editable' => false,
				'Required' => false,
			],
			'TITLE' =>[
				'Name' => static::getEntityFieldCaption($entityTypeId, 'TITLE'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => true,
				'Required' => true,
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
			'OPPORTUNITY_ACCOUNT' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'OPPORTUNITY_ACCOUNT'),
				'Type' => 'string',
				'Filterable' => true,
				'Editable' => false,
				'Required' => false,
			],
			'ACCOUNT_CURRENCY_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'ACCOUNT_CURRENCY_ID'),
				'Type' => 'select',
				'Options' => CCrmCurrencyHelper::PrepareListItems(),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'PROBABILITY' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'PROBABILITY'),
				'Type' => 'string',
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
				'Name' => GetMessage('CRM_DOCUMENT_FIELD_CREATED_BY_ID_DEAL'),
				'Type' => 'user',
			],
			'MODIFY_BY_ID' => [
				'Name' => GetMessage('CRM_DOCUMENT_FIELD_MODIFY_BY_ID'),
				'Type' => 'user',
			],
			'CATEGORY_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CATEGORY_ID'),
				'Type' => 'select',
				'Options' => DealCategory::getSelectListItems(true),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false
			],
			'CATEGORY_ID_PRINTABLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CATEGORY_ID').$printableFieldNameSuffix,
				'Type' => 'string',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			],
			'STAGE_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'STAGE_ID'),
				'Type' => 'select',
				'Options' => DealCategory::getFullStageList(),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
				'Settings' => array('Groups' => DealCategory::getStageGroupInfos())
			],
			'STAGE_ID_PRINTABLE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'STAGE_ID').$printableFieldNameSuffix,
				'Type' => 'string',
				'Filterable' => false,
				'Editable' => false,
				'Required' => false,
			],
			'CLOSED' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CLOSED'),
				'Type' => 'bool',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'TYPE_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'TYPE_ID', GetMessage('CRM_DOCUMENT_DEAL_TYPE_ID')),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('DEAL_TYPE'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'COMMENTS' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'COMMENTS'),
				'Type' => 'text',
				'ValueContentType' => 'bb',
				'Filterable' => false,
				'Editable' => true,
				'Required' => false,
			],
			'BEGINDATE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'BEGINDATE'),
				'Type' => 'date',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'CLOSEDATE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'CLOSEDATE'),
				'Type' => 'datetime',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'EVENT_DATE' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'EVENT_DATE'),
				'Type' => 'datetime',
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'EVENT_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'EVENT_ID'),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('EVENT_TYPE'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'EVENT_DESCRIPTION' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'EVENT_DESCRIPTION'),
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
			"LEAD_ID" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'LEAD_ID'),
				"Type" => "int",
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
			],
			"ORIGINATOR_ID" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'ORIGINATOR_ID'),
				"Type" => "string",
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
			],
			"ORIGIN_ID" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'ORIGIN_ID'),
				"Type" => "string",
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
			],
			"CONTACT_ID" => [
				"Name" => static::getEntityFieldCaption(
					$entityTypeId,
					'CONTACT_ID',
					GetMessage('CRM_DOCUMENT_CRM_ENTITY_TYPE_CONTACT')
				),
				"Type" => "UF:crm",
				"Options" => array('CONTACT' => 'Y'),
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
				"Multiple" => false,
			],
			"CONTACT_IDS" => [
				"Name" => static::getEntityFieldCaption(
					$entityTypeId,
					'CONTACT_IDS',
					GetMessage('CRM_DOCUMENT_FIELD_CONTACT_IDS')
				),
				"Type" => "string",
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
				"Multiple" => true,
			],
			"OBSERVER_IDS" => [
				"Name" => static::getEntityFieldCaption($entityTypeId, 'OBSERVER_IDS', GetMessage('CRM_FIELD_OBSERVER_IDS')),
				"Type" => "user",
				"Filterable" => true,
				"Editable" => false,
				"Required" => false,
				"Multiple" => true,
				'Default' => [],
			],
			"COMPANY_ID" => [
				"Name" => static::getEntityFieldCaption(
					$entityTypeId,
					'COMPANY_ID',
					GetMessage('CRM_DOCUMENT_CRM_ENTITY_TYPE_COMPANY')
				),
				"Type" => "UF:crm",
				"Options" => array('COMPANY' => 'Y'),
				"Filterable" => true,
				"Editable" => true,
				"Required" => false,
				"Multiple" => false,
			],
			'SOURCE_ID' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'SOURCE_ID', GetMessage('CRM_DOCUMENT_FIELD_SOURCE_ID')),
				'Type' => 'select',
				'Options' => CCrmStatus::GetStatusListEx('SOURCE'),
				'Filterable' => true,
				'Editable' => true,
				'Required' => false,
			],
			'SOURCE_DESCRIPTION' => [
				'Name' => static::getEntityFieldCaption($entityTypeId, 'SOURCE_DESCRIPTION', GetMessage('CRM_DOCUMENT_FIELD_SOURCE_DESCRIPTION')),
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
				'Name' => GetMessage('CRM_DOCUMENT_DEAL_IS_RETURN_CUSTOMER'),
				'Type' => 'bool',
				'Editable' => false,
			],
			"ORDER_IDS" => [
				"Name" => GetMessage("CRM_FIELD_ORDER_IDS"),
				"Type" => "int",
				"Multiple" => true,
			],
			'IS_REPEATED_APPROACH' => [
				'Name' => GetMessage('CRM_DOCUMENT_DEAL_IS_REPEATED_APPROACH'),
				'Type' => 'bool',
				'Editable' => false,
			],
			"PRODUCT_IDS" => [
				"Name" => GetMessage("CRM_DOCUMENT_FIELD_PRODUCT_IDS"),
				"Type" => "int",
				"Multiple" => true,
			],
			"PRODUCT_IDS_PRINTABLE" => [
				"Name" => GetMessage("CRM_DOCUMENT_FIELD_PRODUCT_IDS") . $printableFieldNameSuffix,
				"Type" => "text",
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
		$arResult += static::getBadgeFields();

		global $USER_FIELD_MANAGER;
		$CCrmUserType = new CCrmUserType($USER_FIELD_MANAGER, 'CRM_DEAL');
		$CCrmUserType->AddBPFields($arResult, array('PRINTABLE_SUFFIX' => GetMessage("CRM_FIELD_BP_TEXT")));

		//append UTM fields
		$arResult += parent::getUtmFields();

		//append FORM fields
		$arResult += parent::getSiteFormFields(CCrmOwnerType::Deal);

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
			throw new Exception('Entity fields must be array');
		}

		global $DB;
		$document = new WorkflowDocument();
		$arFields = $document->castFields(\CCrmOwnerType::Deal, $arFields);
		$arFields = $document->castDealCategoryAndStage($arFields);

		$useTransaction = static::shouldUseTransaction();

		if ($useTransaction)
		{
			$DB->StartTransaction();
		}

		$CCrmEntity = new CCrmDeal(false);
		$id = $CCrmEntity->Add(
			$arFields,
			true,
			[
				'DISABLE_USER_FIELD_CHECK' => true,
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => $arFields['CREATED_BY_ID'] ?? static::getSystemUserId(),
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
			Crm\Tracking\UI\Details::saveEntityData(\CCrmOwnerType::Deal, $id, $arFields);
		}

		$starter = new CrmStarter(new DocumentDto(\CCrmOwnerType::Deal, (int)$id));
		$result =
			$starter
				->setContextModuleId('bizproc')
				->runOnInnerDocumentAdd(
					new RunDataDto(
						actualFields: $arFields
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
		{
			throw new CBPArgumentNullException('documentId');
		}

		$dbDocumentList = CCrmDeal::GetListEx(
			[],
			[
				'ID' => $arDocumentID['ID'],
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['ID', 'CATEGORY_ID', 'STAGE_ID']
		);

		$arPresentFields = $dbDocumentList->Fetch();
		if (!is_array($arPresentFields))
		{
			throw new Exception(GetMessage('CRM_DOCUMENT_ELEMENT_IS_NOT_FOUND'));
		}

		$document = new WorkflowDocument();
		$arFields = $document->castFields(\CCrmOwnerType::Deal, $arFields, (int)$arDocumentID['ID']);

		//region Category & Stage
		$categoryID = isset($arPresentFields['CATEGORY_ID']) ? (int)$arPresentFields['CATEGORY_ID'] : 0;
		if(isset($arFields['CATEGORY_ID']) && $arFields['CATEGORY_ID'] != $categoryID)
		{
			throw new Exception(GetMessage('CRM_DOCUMENT_DEAL_CATEGORY_CHANGE_ERROR'));
		}

		$arFields = $document->castDealCategoryAndStage($arFields, $categoryID);

		//endregion

		if(empty($arFields))
		{
			return;
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

		if (isset($arFields['OPPORTUNITY']))
		{
			$arFields['IS_MANUAL_OPPORTUNITY'] = $arFields['OPPORTUNITY'] > 0 ? 'Y' : 'N';
		}

		$CCrmEntity = new CCrmDeal(false);
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
				\CCrmOwnerType::Deal,
				$arDocumentID['ID'],
				$arFields
			);
		}

		$starter = new CrmStarter(new DocumentDto(\CCrmOwnerType::Deal, (int)$arDocumentID['ID']));
		$result =
			$starter
				->setContextModuleId('bizproc')
				->runOnInnerDocumentUpdate(
					new RunDataDto(
						actualFields: $arFields,
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
		$dbRes = CCrmDeal::GetListEx(
			[],
			[
				'=ID' => $arDocumentID['ID'],
				'CHECK_PERMISSIONS' => 'N',
			],
			false,
			false,
			['TITLE']
		);
		$arRes = $dbRes ? $dbRes->Fetch() : null;
		return $arRes ? $arRes['TITLE'] : '';
	}

	public static function getDocumentCategories($documentType)
	{
		$factory = Crm\Service\Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		$categories = null;

		if ($factory->isCategoriesSupported())
		{
			$categories = [];
			foreach ($factory->getCategories() as $category)
			{
				$categories[$category->getId()] = [
					'id' => $category->getId(),
					'name' => $category->getName(),
				];
			}
		}

		return $categories;
	}

	public static function getDocumentCategoryId(string $documentId): ?int
	{
		$documentInfo = self::GetDocumentInfo($documentId);
		if (!$documentInfo)
		{
			return null;
		}

		$factory = Crm\Service\Container::getInstance()->getFactory(CCrmOwnerType::Deal);

		if ($factory && $factory->isCategoriesSupported())
		{
			$entity = $factory->getItem($documentInfo['ID'], ['CATEGORY_ID']);
			if ($entity)
			{
				return $entity->getCategoryId();
			}
		}

		return null;
	}

	public static function normalizeDocumentId($documentId)
	{
		return parent::normalizeDocumentIdInternal(
			$documentId,
			CCrmOwnerType::DealName,
			CCrmOwnerTypeAbbr::Deal
		);
	}

	public static function createAutomationTarget($documentType, string|int|null $documentId = null)
	{
		if (is_string($documentId))
		{
			[$entityTypeId, $entityId] = CCrmBizProcHelper::resolveEntityIdByDocumentId($documentId);
			if ($entityId > 0 && $entityTypeId === CCrmOwnerType::Deal)
			{
				return Crm\Automation\Factory::getTarget(CCrmOwnerType::Deal, $entityId);
			}
		}

		return Crm\Automation\Factory::createTarget(\CCrmOwnerType::Deal);
	}

	public static function createTestDocument(string $documentType, array $fields, int $createdById): ?string
	{
		return (new WorkflowDocument())->createTestDocument(\CCrmOwnerType::Deal, $fields, $createdById);
	}
}
