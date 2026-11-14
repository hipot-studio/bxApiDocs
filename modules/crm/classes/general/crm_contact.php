<?php
IncludeModuleLangFile(__FILE__);
//@codingStandardsIgnoreFile

use Bitrix\Crm;
use Bitrix\Crm\Binding\ContactCompanyTable;
use Bitrix\Crm\Binding\EntityBinding;
use Bitrix\Crm\Category\PermissionEntityTypeHelper;
use Bitrix\Crm\ContactAddress;
use Bitrix\Crm\Entity\Traits\EntityFieldsNormalizer;
use Bitrix\Crm\Entity\Traits\UserFieldPreparer;
use Bitrix\Crm\EntityAddress;
use Bitrix\Crm\EntityAddressType;
use Bitrix\Crm\FieldContext\EntityFactory;
use Bitrix\Crm\FieldContext\ValueFiller;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\Catalog\Contractor;
use Bitrix\Crm\Integration\Im\ProcessEntity\NotificationManager;
use Bitrix\Crm\Integrity\DuplicateBankDetailCriterion;
use Bitrix\Crm\Integrity\DuplicateCommunicationCriterion;
use Bitrix\Crm\Integrity\DuplicateIndexMismatch;
use Bitrix\Crm\Integrity\DuplicateManager;
use Bitrix\Crm\Integrity\DuplicateRequisiteCriterion;
use Bitrix\Crm\Item;
use Bitrix\Crm\Model\LastCommunicationTable;
use Bitrix\Crm\Security\QueryBuilder\OptionsBuilder;
use Bitrix\Crm\Security\QueryBuilder\Result\JoinWithUnionSpecification;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Tracking;
use Bitrix\Crm\UtmTable;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\HtmlFilter;

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/crm/lib/webform/entity.php');

class CAllCrmContact
{
	use UserFieldPreparer;
	use EntityFieldsNormalizer;

	static public $sUFEntityID = 'CRM_CONTACT';

	const USER_FIELD_ENTITY_ID = 'CRM_CONTACT';
	const SUSPENDED_USER_FIELD_ENTITY_ID = 'CRM_CONTACT_SPD';
	const TOTAL_COUNT_CACHE_ID = 'crm_contact_total_count';
	const CACHE_TTL = 3600;

	protected const TABLE_NAME = 'b_crm_contact';

	public $LAST_ERROR = '';
	protected $checkExceptions = array();

	protected $bCheckPermission = true;
	const TABLE_ALIAS = 'L';
	protected static $TYPE_NAME = 'CONTACT';
	private static $FIELD_INFOS = null;
	const DEFAULT_FORM_ID = 'CRM_CONTACT_SHOW_V12';

	/**
	 * @var \Bitrix\Crm\Entity\Compatibility\Adapter[]
	 */
	private static array $lastActivityAdapter = [];

	/** @var Crm\Entity\Compatibility\Adapter */
	private $compatibilityAdapter;

	private static ?Crm\Entity\Compatibility\Adapter\Permissions $permissionsAdapter = null;

	function __construct($bCheckPermission = true)
	{
		$this->bCheckPermission = $bCheckPermission;
	}

	/**
	 * Returns true if this class should invoke Service\Operation instead old API.
	 *
	 * @deprecated New API is always used; kept as a compatibility shim for portal customizations.
	 * @return bool
	 */
	public function isUseOperation(): bool
	{
		return true;
	}

	private function getCompatibilityAdapter(): Crm\Entity\Compatibility\Adapter
	{
		if (!$this->compatibilityAdapter)
		{
			$this->compatibilityAdapter = static::createCompatibilityAdapter();

			if ($this->compatibilityAdapter instanceof Crm\Entity\Compatibility\Adapter\Operation)
			{
				$this->compatibilityAdapter
					//bind newly created adapter to this instance
					->setCheckPermissions((bool)$this->bCheckPermission)
					->setErrorMessageContainer($this->LAST_ERROR)
					->setCheckExceptionsContainer($this->checkExceptions)
				;
			}
		}

		return $this->compatibilityAdapter;
	}

	private static function createCompatibilityAdapter(): Bitrix\Crm\Entity\Compatibility\Adapter
	{
		$factory = Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
		if (!$factory)
		{
			throw new Error('No factory for contact');
		}

		$compatibilityAdapter =
			(new Crm\Entity\Compatibility\Adapter\Operation($factory))
				->setRunBizProc(false)
				->setRunAutomation(false)
				->setAlwaysExposedFields([
					'ID',
					'MODIFY_BY_ID',
					'FULL_NAME',
				])
				->setExposedOnlyAfterAddFields([
					'CREATED_BY_ID',
					'ASSIGNED_BY_ID',
					'LAST_NAME',
					'CATEGORY_ID',
					'BIRTHDAY_SORT',
					'HAS_IMOL',
					'HAS_PHONE',
					'HAS_EMAIL',
				])
		;

		$addressAdapter = new Crm\Entity\Compatibility\Adapter\Address(\CCrmOwnerType::Contact, EntityAddressType::Primary);
		$compatibilityAdapter->addChild($addressAdapter);

		return $compatibilityAdapter;
	}

	private static function getLastActivityAdapter(string $tableAlias = self::TABLE_ALIAS): Crm\Entity\Compatibility\Adapter
	{
		if (!isset(self::$lastActivityAdapter[$tableAlias]))
		{
			$factory = Crm\Service\Container::getInstance()->getFactory(\CCrmOwnerType::Contact);
			self::$lastActivityAdapter[$tableAlias] = new Crm\Entity\Compatibility\Adapter\LastActivity($factory);
			self::$lastActivityAdapter[$tableAlias]->setTableAlias($tableAlias);
		}

		return self::$lastActivityAdapter[$tableAlias];
	}

	private static function getPermissionsAdapter(): Crm\Entity\Compatibility\Adapter\Permissions
	{
		if (!self::$permissionsAdapter)
		{
			self::$permissionsAdapter = new Crm\Entity\Compatibility\Adapter\Permissions(\CCrmOwnerType::Contact);
		}

		return self::$permissionsAdapter;
	}

	// Service -->
	public static function GetFieldCaption($fieldName)
	{
		if(\CCrmFieldMulti::IsSupportedType($fieldName))
		{
			return \CCrmFieldMulti::GetEntityTypeCaption($fieldName);
		}

		$result = GetMessage("CRM_CONTACT_FIELD_{$fieldName}_NEW");
		if (!(is_string($result) && $result !== ''))
		{
			$result = GetMessage("CRM_CONTACT_FIELD_{$fieldName}");
		}

		if (!(is_string($result) && $result !== '')
			&& Crm\Tracking\UI\Details::isTrackingField($fieldName))
		{
			$result = Crm\Tracking\UI\Details::getFieldCaption($fieldName);
		}

		if (Crm\Service\ParentFieldManager::isParentFieldName($fieldName))
		{
			$entityTypeId = Crm\Service\ParentFieldManager::getEntityTypeIdFromFieldName($fieldName);
			$result = \CCrmOwnerType::GetDescription($entityTypeId);
		}
		// get caption from tablet
		if (!(is_string($result) && $result !== ''))
		{
			if (Crm\ContactTable::getEntity()->hasField($fieldName))
			{
				$result = Crm\ContactTable::getEntity()->getField($fieldName)->getTitle();
				if($result === $fieldName) // to avoid $result = 'UF_CRM_xxx' for user fields
				{
					$result = '';
				}
			}
		}

		return is_string($result) ? $result : '';
	}
	// Get Fields Metadata
	public static function GetFieldsInfo()
	{
		if (self::$FIELD_INFOS)
		{
			return self::$FIELD_INFOS;
		}

		self::$FIELD_INFOS = array(
			'ID' => array(
				'TYPE' => 'integer',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'HONORIFIC' => array(
				'TYPE' => 'crm_status',
				'CRM_STATUS_TYPE' => 'HONORIFIC'
			),
			'NAME' => array(
				'TYPE' => 'string',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Required)
			),
			'SECOND_NAME' => array(
				'TYPE' => 'string',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Required)
			),
			'LAST_NAME' => array(
				'TYPE' => 'string',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Required)
			),
			'FULL_NAME' => array(
				'TYPE' => 'string',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Hidden)
			),
			'PHOTO' => array(
				'TYPE' => 'file',
				'VALUE_TYPE' => 'image',
			),
			'BIRTHDATE' => array(
				'TYPE' => 'date'
			),
			'BIRTHDAY_SORT' => array(
				'TYPE' => 'integer',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Hidden)
			),
			'TYPE_ID' => array(
				'TYPE' => 'crm_status',
				'CRM_STATUS_TYPE' => 'CONTACT_TYPE',
				'ATTRIBUTES' => [CCrmFieldInfoAttr::HasDefaultValue]
			),
			'SOURCE_ID' => array(
				'TYPE' => 'crm_status',
				'CRM_STATUS_TYPE' => 'SOURCE',
				'ATTRIBUTES' => [CCrmFieldInfoAttr::HasDefaultValue]
			),
			'SOURCE_DESCRIPTION' => array(
				'TYPE' => 'string'
			),
			'POST' => array(
				'TYPE' => 'string'
			),
			'ADDRESS' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_2' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_CITY' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_POSTAL_CODE' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_REGION' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_PROVINCE' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_COUNTRY' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_COUNTRY_CODE' => array(
				'TYPE' => 'string'
			),
			'ADDRESS_LOC_ADDR_ID' => array(
				'TYPE' => 'integer'
			),
			'COMMENTS' => array(
				'TYPE' => 'string',
				'VALUE_TYPE' => 'html',
			),
			'OPENED' => array(
				'TYPE' => 'char'
			),
			'EXPORT' => array(
				'TYPE' => 'char'
			),
			'HAS_PHONE' => array(
				'TYPE' => 'char',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'HAS_EMAIL' => array(
				'TYPE' => 'char',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'HAS_IMOL' => array(
				'TYPE' => 'char',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'ASSIGNED_BY_ID' => array(
				'TYPE' => 'user'
			),
			'CREATED_BY_ID' => array(
				'TYPE' => 'user',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'MODIFY_BY_ID' => array(
				'TYPE' => 'user',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'DATE_CREATE' => array(
				'TYPE' => 'datetime',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'DATE_MODIFY' => array(
				'TYPE' => 'datetime',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly)
			),
			'COMPANY_ID' => array(
				'TYPE' => 'crm_company',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Deprecated)
			),
			'COMPANY_IDS' => array(
				'TYPE' => 'crm_company',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::Multiple)
			),
			'LEAD_ID' => array(
				'TYPE' => 'crm_lead',
				'ATTRIBUTES' => array(CCrmFieldInfoAttr::ReadOnly),
				'SETTINGS' => [
					'parentEntityTypeId' => \CCrmOwnerType::Lead,
				],
			),
			'ORIGINATOR_ID' => array(
				'TYPE' => 'string'
			),
			'ORIGIN_ID' => array(
				'TYPE' => 'string'
			),
			'ORIGIN_VERSION' => array(
				'TYPE' => 'string'
			)
		);

		// add utm fields
		self::$FIELD_INFOS = self::$FIELD_INFOS + UtmTable::getUtmFieldsInfo();
		self::$FIELD_INFOS += Crm\Service\Container::getInstance()->getParentFieldManager()->getParentFieldsInfo(\CCrmOwnerType::Contact);

		self::$FIELD_INFOS += self::getLastActivityAdapter()->getFieldsInfo();

		self::$FIELD_INFOS += LastCommunicationTable::getLastStateFieldInfo();

		return self::$FIELD_INFOS;
	}

	public static function GetFields($arOptions = null)
	{
		$tableAliasName =
			(isset($arOptions['TABLE_ALIAS']) && is_string($arOptions['TABLE_ALIAS']) && $arOptions['TABLE_ALIAS'] !== '')
				? $arOptions['TABLE_ALIAS']
				: self::TABLE_ALIAS
		;

		$assignedByJoin = 'LEFT JOIN b_user U ON ' . $tableAliasName . '.ASSIGNED_BY_ID = U.ID';
		$createdByJoin = 'LEFT JOIN b_user U2 ON ' . $tableAliasName . '.CREATED_BY_ID = U2.ID';
		$modifyByJoin = 'LEFT JOIN b_user U3 ON ' . $tableAliasName . '.MODIFY_BY_ID = U3.ID';

		$result = [
			'ID' => ['FIELD' => $tableAliasName . '.ID', 'TYPE' => 'int'],
			'POST' => ['FIELD' => $tableAliasName . '.POST', 'TYPE' => 'string'],

			'COMMENTS' => ['FIELD' => $tableAliasName . '.COMMENTS', 'TYPE' => 'string'],
			'HONORIFIC' => ['FIELD' => $tableAliasName . '.HONORIFIC', 'TYPE' => 'string'],
			'NAME' => ['FIELD' => $tableAliasName . '.NAME', 'TYPE' => 'string'],
			'SECOND_NAME' => ['FIELD' => $tableAliasName . '.SECOND_NAME', 'TYPE' => 'string'],
			'LAST_NAME' => ['FIELD' => $tableAliasName . '.LAST_NAME', 'TYPE' => 'string'],
			'FULL_NAME' => ['FIELD' => $tableAliasName . '.FULL_NAME', 'TYPE' => 'string'],

			'PHOTO' => ['FIELD' => $tableAliasName . '.PHOTO', 'TYPE' => 'string'],
			'LEAD_ID' => ['FIELD' => $tableAliasName . '.LEAD_ID', 'TYPE' => 'int'],
			'TYPE_ID' => ['FIELD' => $tableAliasName . '.TYPE_ID', 'TYPE' => 'string'],

			'SOURCE_ID' => ['FIELD' => $tableAliasName . '.SOURCE_ID', 'TYPE' => 'string'],
			'SOURCE_DESCRIPTION' => ['FIELD' => $tableAliasName . '.SOURCE_DESCRIPTION', 'TYPE' => 'string'],

			'COMPANY_ID' => ['FIELD' => $tableAliasName . '.COMPANY_ID', 'TYPE' => 'int'],
			'COMPANY_TITLE' => [
				'FIELD' => 'C.TITLE',
				'TYPE' => 'string',
				'FROM' => 'LEFT JOIN b_crm_company C ON ' . $tableAliasName . '.COMPANY_ID = C.ID'
			],
			'COMPANY_LOGO' => [
				'FIELD' => 'C.LOGO',
				'TYPE' => 'int',
				'FROM' => 'LEFT JOIN b_crm_company C ON ' . $tableAliasName . '.COMPANY_ID = C.ID'
			],
			'BIRTHDATE' => ['FIELD' => $tableAliasName . '.BIRTHDATE', 'TYPE' => 'date'],
			'BIRTHDAY_SORT' => ['FIELD' => $tableAliasName . '.BIRTHDAY_SORT', 'TYPE' => 'int'],
			'EXPORT' => ['FIELD' => $tableAliasName . '.EXPORT', 'TYPE' => 'char'],

			'HAS_PHONE' => ['FIELD' => $tableAliasName . '.HAS_PHONE', 'TYPE' => 'char'],
			'HAS_EMAIL' => ['FIELD' => $tableAliasName . '.HAS_EMAIL', 'TYPE' => 'char'],
			'HAS_IMOL' => ['FIELD' => $tableAliasName . '.HAS_IMOL', 'TYPE' => 'char'],

			'DATE_CREATE' => ['FIELD' => $tableAliasName . '.DATE_CREATE', 'TYPE' => 'datetime'],
			'DATE_MODIFY' => ['FIELD' => $tableAliasName . '.DATE_MODIFY', 'TYPE' => 'datetime'],

			'ASSIGNED_BY_ID' => ['FIELD' => $tableAliasName . '.ASSIGNED_BY_ID', 'TYPE' => 'int'],
			'ASSIGNED_BY_LOGIN' => ['FIELD' => 'U.LOGIN', 'TYPE' => 'string', 'FROM' => $assignedByJoin],
			'ASSIGNED_BY_NAME' => ['FIELD' => 'U.NAME', 'TYPE' => 'string', 'FROM' => $assignedByJoin],
			'ASSIGNED_BY_LAST_NAME' => ['FIELD' => 'U.LAST_NAME', 'TYPE' => 'string', 'FROM' => $assignedByJoin],
			'ASSIGNED_BY_SECOND_NAME' => ['FIELD' => 'U.SECOND_NAME', 'TYPE' => 'string', 'FROM' => $assignedByJoin],
			'ASSIGNED_BY_WORK_POSITION' => ['FIELD' => 'U.WORK_POSITION', 'TYPE' => 'string', 'FROM' => $assignedByJoin],
			'ASSIGNED_BY_PERSONAL_PHOTO' => ['FIELD' => 'U.PERSONAL_PHOTO', 'TYPE' => 'string', 'FROM' => $assignedByJoin],

			'CREATED_BY_ID' => ['FIELD' => $tableAliasName . '.CREATED_BY_ID', 'TYPE' => 'int'],
			'CREATED_BY_LOGIN' => ['FIELD' => 'U2.LOGIN', 'TYPE' => 'string', 'FROM' => $createdByJoin],
			'CREATED_BY_NAME' => ['FIELD' => 'U2.NAME', 'TYPE' => 'string', 'FROM' => $createdByJoin],
			'CREATED_BY_LAST_NAME' => ['FIELD' => 'U2.LAST_NAME', 'TYPE' => 'string', 'FROM' => $createdByJoin],
			'CREATED_BY_SECOND_NAME' => ['FIELD' => 'U2.SECOND_NAME', 'TYPE' => 'string', 'FROM' => $createdByJoin],

			'MODIFY_BY_ID' => ['FIELD' => $tableAliasName . '.MODIFY_BY_ID', 'TYPE' => 'int'],
			'MODIFY_BY_LOGIN' => ['FIELD' => 'U3.LOGIN', 'TYPE' => 'string', 'FROM' => $modifyByJoin],
			'MODIFY_BY_NAME' => ['FIELD' => 'U3.NAME', 'TYPE' => 'string', 'FROM' => $modifyByJoin],
			'MODIFY_BY_LAST_NAME' => ['FIELD' => 'U3.LAST_NAME', 'TYPE' => 'string', 'FROM' => $modifyByJoin],
			'MODIFY_BY_SECOND_NAME' => ['FIELD' => 'U3.SECOND_NAME', 'TYPE' => 'string', 'FROM' => $modifyByJoin],

			'OPENED' => ['FIELD' => $tableAliasName . '.OPENED', 'TYPE' => 'char'],
			'WEBFORM_ID' => ['FIELD' => $tableAliasName . '.WEBFORM_ID', 'TYPE' => 'int'],
			'ORIGINATOR_ID' => ['FIELD' => $tableAliasName . '.ORIGINATOR_ID', 'TYPE' => 'string'], //EXTERNAL SYSTEM THAT OWNS THIS ITEM
			'ORIGIN_ID' => ['FIELD' => $tableAliasName . '.ORIGIN_ID', 'TYPE' => 'string'], //ITEM ID IN EXTERNAL SYSTEM
			'ORIGIN_VERSION' => ['FIELD' => $tableAliasName . '.ORIGIN_VERSION', 'TYPE' => 'string'], //ITEM VERSION IN EXTERNAL SYSTEM

			'CATEGORY_ID' => ['FIELD' => $tableAliasName . '.CATEGORY_ID', 'TYPE' => 'int'],
		];

		if (!(is_array($arOptions) && isset($arOptions['DISABLE_ADDRESS']) && $arOptions['DISABLE_ADDRESS']))
		{
			if (COption::GetOptionString('crm', '~CRM_CONVERT_CONTACT_ADDRESSES', 'N') === 'Y')
			{
				$addrJoin = 'LEFT JOIN b_crm_addr ADDR ON ' . $tableAliasName . '.ID = ADDR.ENTITY_ID AND ADDR.TYPE_ID = '
					.EntityAddressType::Primary.' AND ADDR.ENTITY_TYPE_ID = '.CCrmOwnerType::Contact;
			}
			else
			{
				$addrJoin = 'LEFT JOIN b_crm_addr ADDR ON ' . $tableAliasName . '.ID = ADDR.ANCHOR_ID AND ADDR.TYPE_ID = '
					.EntityAddressType::Primary.' AND ADDR.ANCHOR_TYPE_ID = '.CCrmOwnerType::Contact.
					' AND ADDR.IS_DEF = 1';
			}

			$result['ADDRESS'] = ['FIELD' => 'ADDR.ADDRESS_1', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_2'] = ['FIELD' => 'ADDR.ADDRESS_2', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_CITY'] = ['FIELD' => 'ADDR.CITY', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_POSTAL_CODE'] = ['FIELD' => 'ADDR.POSTAL_CODE', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_REGION'] = ['FIELD' => 'ADDR.REGION', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_PROVINCE'] = ['FIELD' => 'ADDR.PROVINCE', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_COUNTRY'] = ['FIELD' => 'ADDR.COUNTRY', 'TYPE' => 'string', 'FROM' => $addrJoin];
			$result['ADDRESS_LOC_ADDR_ID'] = ['FIELD' => 'ADDR.LOC_ADDR_ID', 'TYPE' => 'integer', 'FROM' => $addrJoin];
		}

		$needAddFieldAliases = (
			!isset($arOptions['ADD_FIELD_ALIASES'])
			|| $arOptions['ADD_FIELD_ALIASES']
		);

		if ($needAddFieldAliases)
		{
			// Creation of field aliases
			$result['ASSIGNED_BY'] = $result['ASSIGNED_BY_ID'];
			$result['CREATED_BY'] = $result['CREATED_BY_ID'];
			$result['MODIFY_BY'] = $result['MODIFY_BY_ID'];
		}

		$additionalFields = is_array($arOptions) && isset($arOptions['ADDITIONAL_FIELDS'])
			? $arOptions['ADDITIONAL_FIELDS'] : null;

		if(is_array($additionalFields))
		{
			if(in_array('ACTIVITY', $additionalFields, true))
			{
				$commonActivityJoin = CCrmActivity::PrepareJoin(0, CCrmOwnerType::Contact, $tableAliasName, 'AC', 'UAC', 'ACUSR');

				$result['C_ACTIVITY_ID'] = ['FIELD' => 'UAC.ACTIVITY_ID', 'TYPE' => 'int', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_TIME'] = ['FIELD' => 'UAC.ACTIVITY_TIME', 'TYPE' => 'datetime', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_SUBJECT'] = ['FIELD' => 'AC.SUBJECT', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_RESP_ID'] = ['FIELD' => 'AC.RESPONSIBLE_ID', 'TYPE' => 'int', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_RESP_LOGIN'] = ['FIELD' => 'ACUSR.LOGIN', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_RESP_NAME'] = ['FIELD' => 'ACUSR.NAME', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_RESP_LAST_NAME'] = ['FIELD' => 'ACUSR.LAST_NAME', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_RESP_SECOND_NAME'] = ['FIELD' => 'ACUSR.SECOND_NAME', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_TYPE_ID'] = ['FIELD' => 'AC.TYPE_ID', 'TYPE' => 'int', 'FROM' => $commonActivityJoin];
				$result['C_ACTIVITY_PROVIDER_ID'] = ['FIELD' => 'AC.PROVIDER_ID', 'TYPE' => 'string', 'FROM' => $commonActivityJoin];

				$userID = self::getPermissionsAdapter()->getCurrentUserFromOptions();
				if($userID > 0)
				{
					$activityJoin = CCrmActivity::PrepareJoin($userID, CCrmOwnerType::Contact, $tableAliasName, 'A', 'UA', '');

					$result['ACTIVITY_ID'] = ['FIELD' => 'UA.ACTIVITY_ID', 'TYPE' => 'int', 'FROM' => $activityJoin];
					$result['ACTIVITY_TIME'] = ['FIELD' => 'UA.ACTIVITY_TIME', 'TYPE' => 'datetime', 'FROM' => $activityJoin];
					$result['ACTIVITY_SUBJECT'] = ['FIELD' => 'A.SUBJECT', 'TYPE' => 'string', 'FROM' => $activityJoin];
					$result['ACTIVITY_TYPE_ID'] = ['FIELD' => 'A.TYPE_ID', 'TYPE' => 'int', 'FROM' => $activityJoin];
					$result['ACTIVITY_PROVIDER_ID'] = ['FIELD' => 'A.PROVIDER_ID', 'TYPE' => 'string', 'FROM' => $activityJoin];
				}
			}
		}

		// add utm fields
		$result = array_merge($result, UtmTable::getFieldsDescriptionByEntityTypeId(CCrmOwnerType::Contact, $tableAliasName));
		$result = array_merge(
			$result,
			Crm\Service\Container::getInstance()->getParentFieldManager()->getParentFieldsSqlInfo(
				CCrmOwnerType::Contact,
				$tableAliasName
			),
			LastCommunicationTable::getFieldsByEntityTypeId(CCrmOwnerType::Contact, true),
		);

		$result += self::getLastActivityAdapter($tableAliasName)->getFields();

		return $result;
	}
	// <-- Service

	public static function GetUserFieldEntityID()
	{
		return self::$sUFEntityID;
	}

	public static function GetUserFields()
	{
		global $USER_FIELD_MANAGER;
		return $USER_FIELD_MANAGER->GetUserFields(self::$sUFEntityID);
	}

	// GetList with navigation support
	public static function GetListEx($arOrder = array(), $arFilter = array(), $arGroupBy = false, $arNavStartParams = false, $arSelectFields = array(), $arOptions = array())
	{
		if(!is_array($arOptions))
		{
			$arOptions = array();
		}

		if(!isset($arOptions['PERMISSION_SQL_TYPE']))
		{
			$arOptions['PERMISSION_SQL_TYPE'] = 'FROM';
			$arOptions['PERMISSION_SQL_UNION'] = 'DISTINCT';
		}

		$arOptions['RESTRICT_BY_ENTITY_TYPES'] = (new PermissionEntityTypeHelper(CCrmOwnerType::Contact))->getPermissionEntityTypesFromFilter((array)$arFilter);

		if (JoinWithUnionSpecification::getInstance()->isSatisfiedBy($arFilter))
		{
			// When forming a request for restricting rights, the optimization mode with the use of union was used.
			$arOptions['PERMISSION_BUILDER_OPTION_OBSERVER_JOIN_AS_UNION'] = true;
		}

		$lb = new CCrmEntityListBuilder(
			CCrmContact::DB_TYPE,
			CCrmContact::TABLE_NAME,
			self::TABLE_ALIAS,
			self::GetFields(isset($arOptions['FIELD_OPTIONS']) ? $arOptions['FIELD_OPTIONS'] : null),
			self::$sUFEntityID,
			'CONTACT',
			array('CCrmContact', 'BuildPermSql'),
			array('CCrmContact', '__AfterPrepareSql')
		);

		return $lb->Prepare($arOrder, $arFilter, $arGroupBy, $arNavStartParams, $arSelectFields, $arOptions);
	}

	public static function CreateListBuilder(array $arFieldOptions = null)
	{
		return new CCrmEntityListBuilder(
			CCrmContact::DB_TYPE,
			CCrmContact::TABLE_NAME,
			self::TABLE_ALIAS,
			self::GetFields($arFieldOptions),
			self::$sUFEntityID,
			'CONTACT',
			array('CCrmContact', 'BuildPermSql'),
			array('CCrmContact', '__AfterPrepareSql')
		);
	}

	public static function Exists($ID)
	{
		$ID = intval($ID);
		if($ID <= 0)
		{
			return false;
		}

		$dbRes = self::GetListEx(
			array(),
			array('ID' => $ID, 'CHECK_PERMISSIONS' => 'N'),
			false,
			false,
			array('ID')
		);

		return is_array($dbRes->Fetch());
	}

	public static function GetTopIDs($top, $sortType = 'ASC')
	{
		$top = (int)$top;
		if ($top <= 0)
		{
			return [];
		}

		$sortType = mb_strtoupper($sortType) !== 'DESC' ? 'ASC' : 'DESC';

		return \Bitrix\Crm\Entity\Contact::getInstance()->getTopIDs([
			'order' => ['ID' => $sortType],
			'limit' => $top,
		]);
	}

	public static function GetTopIDsInCategory($categoryId, $top, $sortType = 'ASC')
	{
		$top = (int)$top;
		if ($top <= 0)
		{
			return [];
		}

		$sortType = mb_strtoupper($sortType) !== 'DESC' ? 'ASC' : 'DESC';

		return \Bitrix\Crm\Entity\Contact::getInstance()->getTopIDs([
			'order' => ['ID' => $sortType],
			'limit' => $top,
			'filter' => ['@CATEGORY_ID' => $categoryId],
		]);
	}

	public static function GetTotalCount(?int $categoryId = 0)
	{
		$canUseCache = defined('BX_COMP_MANAGED_CACHE');

		$cacheId = self::TOTAL_COUNT_CACHE_ID;
		if ($categoryId > 0)
		{
			$cacheId .= '_c' . $categoryId;
		}
		elseif ($categoryId === null)
		{
			$cacheId .= '_all';
		}

		if($canUseCache && $GLOBALS['CACHE_MANAGER']->Read(self::CACHE_TTL, $cacheId, 'b_crm_contact'))
		{
			return $GLOBALS['CACHE_MANAGER']->Get($cacheId);
		}

		$filter = [
			'CHECK_PERMISSIONS' => 'N',
		];
		if ($categoryId !== null)
		{
			$filter['@CATEGORY_ID'] = $categoryId;
		}
		$result = (int)self::GetListEx(
			[],
			$filter,
			[],
			false,
			[],
			['ENABLE_ROW_COUNT_THRESHOLD' => false]
		);

		if($canUseCache)
		{
			$GLOBALS['CACHE_MANAGER']->Set($cacheId, $result);
		}

		return $result;
	}

	public static function GetRightSiblingID($ID)
	{
		$ID = intval($ID);
		if($ID <= 0)
		{
			return 0;
		}

		$dbRes = self::GetListEx(
			array('ID' => 'ASC'),
			array('>ID' => $ID, 'CHECK_PERMISSIONS' => 'N'),
			false,
			array('nTopCount' => 1),
			array('ID')
		);

		$arRes =  $dbRes->Fetch();
		if(!is_array($arRes))
		{
			return 0;
		}

		return intval($arRes['ID']);
	}

	public static function GetLeftSiblingID($ID)
	{
		$ID = intval($ID);
		if($ID <= 0)
		{
			return 0;
		}

		$dbRes = self::GetListEx(
			array('ID' => 'DESC'),
			array('<ID' => $ID, 'CHECK_PERMISSIONS' => 'N'),
			false,
			array('nTopCount' => 1),
			array('ID')
		);

		$arRes =  $dbRes->Fetch();
		if(!is_array($arRes))
		{
			return 0;
		}

		return intval($arRes['ID']);
	}

	/**
	 *
	 * @param array $arOrder
	 * @param array $arFilter
	 * @param array $arSelect
	 * @return CDBResult
	 */
	public static function GetList($arOrder = array('DATE_CREATE' => 'DESC'), $arFilter = array(), $arSelect = array(), $nPageTop = false)
	{
		global $DB, $USER_FIELD_MANAGER;

		//fields
		$arFields = array(
			'ID' => 'L.ID',
			'POST' => 'L.POST',
			'ADDRESS' => 'L.ADDRESS',
			'COMMENTS' => 'L.COMMENTS',
			'NAME' => 'L.NAME',
			'LEAD_ID' => 'L.LEAD_ID',
			'TYPE_ID' => 'L.TYPE_ID',
			'SOURCE_ID' => 'L.SOURCE_ID',
			'COMPANY_ID' => 'L.COMPANY_ID',
			'COMPANY_TITLE' => 'C.TITLE',
			'SOURCE_DESCRIPTION' => 'L.SOURCE_DESCRIPTION',
			'PHOTO' => 'L.PHOTO',
			'SECOND_NAME' => 'L.SECOND_NAME',
			'LAST_NAME' => 'L.LAST_NAME',
			'FULL_NAME' => 'L.FULL_NAME',
			'BIRTHDATE' => $DB->DateToCharFunction('L.BIRTHDATE'),
			'EXPORT' => 'L.EXPORT',
			'ASSIGNED_BY' => 'L.ASSIGNED_BY_ID',
			'ASSIGNED_BY_ID' => 'L.ASSIGNED_BY_ID',
			'CREATED_BY' => 'L.CREATED_BY_ID',
			'CREATED_BY_ID' => 'L.CREATED_BY_ID',
			'MODIFY_BY' => 'L.MODIFY_BY_ID',
			'MODIFY_BY_ID' => 'L.MODIFY_BY_ID',
			'DATE_CREATE' => $DB->DateToCharFunction('L.DATE_CREATE'),
			'DATE_MODIFY' => $DB->DateToCharFunction('L.DATE_MODIFY'),
			'OPENED' => 'L.OPENED',
			'ORIGINATOR_ID' => 'L.ORIGINATOR_ID', //EXTERNAL SYSTEM THAT OWNS THIS ITEM
			'ORIGIN_ID' => 'L.ORIGIN_ID', //ITEM ID IN EXTERNAL SYSTEM
			'CATEGORY_ID' => 'L.CATEGORY_ID',

			'ASSIGNED_BY_LOGIN' => 'U.LOGIN',
			'ASSIGNED_BY_NAME' => 'U.NAME',
			'ASSIGNED_BY_LAST_NAME' => 'U.LAST_NAME',
			'ASSIGNED_BY_SECOND_NAME' => 'U.SECOND_NAME',
			'CREATED_BY_LOGIN' => 'U2.LOGIN',
			'CREATED_BY_NAME' => 'U2.NAME',
			'CREATED_BY_LAST_NAME' => 'U2.LAST_NAME',
			'CREATED_BY_SECOND_NAME' => 'U2.SECOND_NAME',
			'MODIFY_BY_LOGIN' => 'U3.LOGIN',
			'MODIFY_BY_NAME' => 'U3.NAME',
			'MODIFY_BY_LAST_NAME' => 'U3.LAST_NAME',
			'MODIFY_BY_SECOND_NAME' => 'U3.SECOND_NAME'
		);

		$arSqlSelect = array();
		$sSqlJoin = '';
		if (count($arSelect) == 0)
			$arSelect = array_merge(array_keys($arFields), array('UF_*'));

		$obQueryWhere = new CSQLWhere();
		$arFilterField = $arSelect;
		foreach ($arFilter as $sKey => $sValue)
		{
			$arField = $obQueryWhere->MakeOperation($sKey);
			$arFilterField[] = $arField['FIELD'];
		}

		if (in_array('ASSIGNED_BY_LOGIN', $arFilterField) || in_array('ASSIGNED_BY', $arFilterField))
		{
			$arSelect[] = 'ASSIGNED_BY_LOGIN';
			$arSelect[] = 'ASSIGNED_BY_NAME';
			$arSelect[] = 'ASSIGNED_BY_LAST_NAME';
			$arSelect[] = 'ASSIGNED_BY_SECOND_NAME';
			$sSqlJoin .= ' LEFT JOIN b_user U ON L.ASSIGNED_BY_ID = U.ID ';
		}
		if (in_array('CREATED_BY_LOGIN', $arFilterField))
		{
			$arSelect[] = 'CREATED_BY';
			$arSelect[] = 'CREATED_BY_LOGIN';
			$arSelect[] = 'CREATED_BY_NAME';
			$arSelect[] = 'CREATED_BY_LAST_NAME';
			$arSelect[] = 'CREATED_BY_SECOND_NAME';
			$sSqlJoin .= ' LEFT JOIN b_user U2 ON L.CREATED_BY_ID = U2.ID ';
		}
		if (in_array('MODIFY_BY_LOGIN', $arFilterField))
		{
			$arSelect[] = 'MODIFY_BY';
			$arSelect[] = 'MODIFY_BY_LOGIN';
			$arSelect[] = 'MODIFY_BY_NAME';
			$arSelect[] = 'MODIFY_BY_LAST_NAME';
			$arSelect[] = 'MODIFY_BY_SECOND_NAME';
			$sSqlJoin .= ' LEFT JOIN b_user U3 ON  L.MODIFY_BY_ID = U3.ID ';
		}
		if (in_array('COMPANY_ID', $arFilterField) || in_array('COMPANY_TITLE', $arFilterField))
		{
			$arSelect[] = 'COMPANY_ID';
			$arSelect[] = 'COMPANY_TITLE';
			$sSqlJoin .= ' LEFT JOIN b_crm_company C ON L.COMPANY_ID = C.ID ';
		}

		foreach($arSelect as $field)
		{
			$field = mb_strtoupper($field);
			if (array_key_exists($field, $arFields))
				$arSqlSelect[$field] = $arFields[$field].($field != '*' ? ' AS '.$field : '');
		}

		if (!isset($arSqlSelect['ID']))
			$arSqlSelect['ID'] = $arFields['ID'];
		$sSqlSelect = implode(",\n", $arSqlSelect);

		if (isset($arFilter['FM']) && !empty($arFilter['FM']))
		{
			$res = CCrmFieldMulti::GetList(array('ID' => 'asc'), array('ENTITY_ID' => 'CONTACT', 'FILTER' => $arFilter['FM']));
			$ids = array();
			while($ar = $res->Fetch())
			{
				$ids[] = $ar['ELEMENT_ID'];
			}

			if(count($ids) == 0)
			{
				// Fix for #26789 (nothing found)
				$rs = new CDBResult();
				$rs->InitFromArray(array());
				return $rs;
			}

			$arFilter['ID'] = $ids;
		}

		$obUserFieldsSql = new CUserTypeSQL;
		$obUserFieldsSql->SetEntity(self::$sUFEntityID, 'L.ID');
		$obUserFieldsSql->SetSelect($arSelect);
		$obUserFieldsSql->SetFilter($arFilter);
		$obUserFieldsSql->SetOrder($arOrder);

		$arSqlSearch = array();
		// check permissions
		$sSqlPerm = '';

		if (
			!self::getPermissionsAdapter()->isAdmin()
			&& (!array_key_exists('CHECK_PERMISSIONS', $arFilter) || $arFilter['CHECK_PERMISSIONS'] !== 'N')
		)
		{
			$arPermType = array();
			if (!isset($arFilter['PERMISSION']))
				$arPermType[] = 'READ';
			else
				$arPermType	= is_array($arFilter['PERMISSION']) ? $arFilter['PERMISSION'] : array($arFilter['PERMISSION']);

			$sSqlPerm = self::BuildPermSql('L', $arPermType);
			if ($sSqlPerm === false)
			{
				$CDBResult = new CDBResult();
				$CDBResult->InitFromArray(array());
				return $CDBResult;
			}
			if($sSqlPerm <> '')
			{
				$sSqlPerm = ' AND '.$sSqlPerm;
			}
		}

		// where
		$arWhereFields = array(
			'ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'LEAD_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.LEAD_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'COMPANY_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.COMPANY_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'COMPANY_TITLE' => array(
				'TABLE_ALIAS' => 'C',
				'FIELD_NAME' => 'C.TITLE',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'TYPE_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.TYPE_ID',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'SOURCE_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.SOURCE_ID',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'NAME' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.NAME',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'SECOND_NAME' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.SECOND_NAME',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'LAST_NAME' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.LAST_NAME',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'FULL_NAME' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.FULL_NAME',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'BIRTHDATE' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.BIRTHDATE',
				'FIELD_TYPE' => 'date',
				'JOIN' => false
			),
			'POST' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.POST',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'ADDRESS' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.ADDRESS',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'COMMENTS' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.COMMENTS',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'DATE_CREATE' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.DATE_CREATE',
				'FIELD_TYPE' => 'datetime',
				'JOIN' => false
			),
			'DATE_MODIFY' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.DATE_MODIFY',
				'FIELD_TYPE' => 'datetime',
				'JOIN' => false
			),
			'CREATED_BY_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.CREATED_BY_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'ASSIGNED_BY_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.ASSIGNED_BY_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'OPENED' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.OPENED',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'MODIFY_BY_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.MODIFY_BY_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			),
			'EXPORT' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.EXPORT',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'ORIGINATOR_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.ORIGINATOR_ID',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'ORIGIN_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.ORIGIN_ID',
				'FIELD_TYPE' => 'string',
				'JOIN' => false
			),
			'CATEGORY_ID' => array(
				'TABLE_ALIAS' => 'L',
				'FIELD_NAME' => 'L.CATEGORY_ID',
				'FIELD_TYPE' => 'int',
				'JOIN' => false
			)
		);

		$obQueryWhere->SetFields($arWhereFields);
		if (!is_array($arFilter))
			$arFilter = array();
		$sQueryWhereFields = $obQueryWhere->GetQuery($arFilter);

		$sSqlSearch = '';
		foreach($arSqlSearch as $r)
			if ($r <> '')
				$sSqlSearch .= "\n\t\t\t\tAND  ($r) ";
		$CCrmUserType = new CCrmUserType($GLOBALS['USER_FIELD_MANAGER'], self::$sUFEntityID);
		$CCrmUserType->ListPrepareFilter($arFilter);
		$r = $obUserFieldsSql->GetFilter();
		if ($r <> '')
			$sSqlSearch .= "\n\t\t\t\tAND ($r) ";

		if (!empty($sQueryWhereFields))
			$sSqlSearch .= "\n\t\t\t\tAND ($sQueryWhereFields) ";

		$arFieldsOrder = array(
			'ASSIGNED_BY' => 'L.ASSIGNED_BY_ID',
			'CREATED_BY' => 'L.CREATED_BY_ID',
			'MODIFY_BY' => 'L.MODIFY_BY_ID',
			'DATE_CREATE' => 'L.DATE_CREATE',
			'DATE_MODIFY' => 'L.DATE_MODIFY',
			'COMPANY_ID' => 'C.TITLE'
		);

		// order
		$arSqlOrder = Array();
		if (!is_array($arOrder))
			$arOrder = Array('DATE_CREATE' => 'DESC');
		foreach($arOrder as $by => $order)
		{
			$by = mb_strtoupper($by);
			$order = mb_strtolower($order);
			if ($order != 'asc')
				$order = 'desc';

			if (isset($arFieldsOrder[$by]))
				$arSqlOrder[$by] = " {$arFieldsOrder[$by]} $order ";
			else if (isset($arFields[$by]) && $by != 'ADDRESS')
				$arSqlOrder[$by] = " L.$by $order ";
			else if ($s = $obUserFieldsSql->GetOrder($by))
				$arSqlOrder[$by] = " $s $order ";
			else
			{
				$by = 'date_create';
				$arSqlOrder[$by] = " L.DATE_CREATE $order ";
			}
		}

		if (count($arSqlOrder) > 0)
			$sSqlOrder = "\n\t\t\t\tORDER BY ".implode(', ', $arSqlOrder);
		else
			$sSqlOrder = '';

		$sSql = "
			SELECT
				$sSqlSelect
				{$obUserFieldsSql->GetSelect()}
			FROM
				b_crm_contact L $sSqlJoin
				{$obUserFieldsSql->GetJoin('L.ID')}
			WHERE
				1=1 $sSqlSearch
				$sSqlPerm
			$sSqlOrder";

		if ($nPageTop !== false)
		{
			$nPageTop = (int) $nPageTop;
			$sSql = $DB->TopSql($sSql, $nPageTop);
		}

		$obRes = $DB->Query($sSql);
		$obRes->SetUserFields($USER_FIELD_MANAGER->GetUserFields(self::$sUFEntityID));
		return $obRes;
	}

	public static function GetByID($ID, $bCheckPerms = true)
	{
		$arFilter = array('=ID' => intval($ID));
		if (!$bCheckPerms)
		{
			$arFilter['CHECK_PERMISSIONS'] = 'N';
		}

		$dbRes = CCrmContact::GetListEx(array(), $arFilter);
		return $dbRes->Fetch();
	}

	public static function GetFullName($arFields)
	{
		if(!is_array($arFields))
		{
			return '';
		}

		$name = isset($arFields['NAME']) ? $arFields['NAME'] : '';
		$lastName = isset($arFields['LAST_NAME']) ? $arFields['LAST_NAME'] : '';

		if ($name !== '' && $lastName !== '')
		{
			return "{$name} {$lastName}";
		}

		if ($lastName !== '')
		{
			return $lastName;
		}

		if ($name !== '')
		{
			return $name;
		}

		return '';
	}

	public static function BuildPermSql($sAliasPrefix = 'L', $mPermType = 'READ', $arOptions = [])
	{
		$arOptions = (array)$arOptions;
		$permissionTypeHelper = new PermissionEntityTypeHelper(CCrmOwnerType::Contact);
		$permissionEntityTypes = $permissionTypeHelper->getPermissionEntityTypesFromOptions($arOptions);

		$userId = null;
		if (isset($arOptions['PERMS']) && is_object($arOptions['PERMS']))
		{
			/** @var \CCrmPerms $arOptions['PERMS'] */
			$userId = $arOptions['PERMS']->GetUserID();
		}

		$builderOptions = OptionsBuilder::makeFromArray($arOptions)
			->setOperations((array)$mPermType)
			->setAliasPrefix((string)$sAliasPrefix)
			->setSkipCheckOtherEntityTypes($permissionTypeHelper->getAllowSkipOtherEntityTypesFromOptions($arOptions))
			->build()
		;

		$queryBuilder = Crm\Service\Container::getInstance()
			->getUserPermissions($userId)
			->itemsList()
			->createQueryBuilder($permissionEntityTypes, $builderOptions)
		;

		return $queryBuilder->buildCompatible();
	}

	public static function __AfterPrepareSql($sender, $arOrder, $arFilter, $arGroupBy, $arSelectFields)
	{
		$sqlData = array('FROM' => array(), 'WHERE' => array());
		if(isset($arFilter['SEARCH_CONTENT']) && $arFilter['SEARCH_CONTENT'] !== '')
		{
			$tableAlias = $sender->GetTableAlias();
			$queryWhere = new CSQLWhere();
			$queryWhere->SetFields(
				array(
					'SEARCH_CONTENT' => array(
						'FIELD_NAME' => "{$tableAlias}.SEARCH_CONTENT",
						'FIELD_TYPE' => 'string',
						'JOIN' => false
					)
				)
			);
			$options = [];
			if (isset($arFilter['__ENABLE_SEARCH_CONTENT_PHONE_DETECTION']))
			{
				$options['ENABLE_PHONE_DETECTION'] = $arFilter['__ENABLE_SEARCH_CONTENT_PHONE_DETECTION'];
				unset($arFilter['__ENABLE_SEARCH_CONTENT_PHONE_DETECTION']);
			}
			$query = $queryWhere->GetQuery(
				Crm\Search\SearchEnvironment::prepareEntityFilter(
					CCrmOwnerType::Contact,
					array(
						'SEARCH_CONTENT' => Crm\Search\SearchEnvironment::prepareSearchContent($arFilter['SEARCH_CONTENT'], $options)
					)
				)
			);
			if($query !== '')
			{
				$sqlData['WHERE'][] = $query;
			}
		}

		if(isset($arFilter['ASSOCIATED_COMPANY_TITLE']))
		{
			$sql = ContactCompanyTable::prepareFilterJoinSqlByTitle(
				CCrmOwnerType::Company,
				$arFilter['ASSOCIATED_COMPANY_TITLE'],
				$sender->GetTableAlias()
			);

			if($sql !== '')
			{
				$sqlData['FROM'][] = $sql;
			}
			unset($arFilter['ASSOCIATED_COMPANY_TITLE']);
		}
		if(isset($arFilter['ASSOCIATED_COMPANY_ID']) && $arFilter['ASSOCIATED_COMPANY_ID'] > 0)
		{
			$sqlData['FROM'][] = ContactCompanyTable::prepareFilterJoinSql(
				CCrmOwnerType::Company,
				$arFilter['ASSOCIATED_COMPANY_ID'],
				$sender->GetTableAlias()
			);
		}
		if(isset($arFilter['ASSOCIATED_DEAL_ID']) && $arFilter['ASSOCIATED_DEAL_ID'] > 0)
		{
			$sqlData['FROM'][] = Crm\Binding\DealContactTable::prepareFilterJoinSql(
				CCrmOwnerType::Deal,
				$arFilter['ASSOCIATED_DEAL_ID'],
				$sender->GetTableAlias()
			);
		}
		if (array_key_exists('BINDING_OWNER', $arFilter))
		{
			$bindingOwner = Crm\Binding\OwnerBindingFilter::tryCreateOwnerFromFilterValue($arFilter['BINDING_OWNER']);
			if (
				$bindingOwner
				&& Crm\Binding\OwnerBindingFilter::isSupported(CCrmOwnerType::Contact, $bindingOwner->getEntityTypeId())
			)
			{
				$sqlData['FROM'][] = Crm\Binding\OwnerBindingFilter::prepareJoinSql(
					CCrmOwnerType::Contact,
					$bindingOwner,
					$sender->GetTableAlias()
				);
			}
			else
			{
				$sqlData['WHERE'][] = '1 = 0';
			}
		}
		if(isset($arFilter['ADDRESSES']))
		{
			foreach($arFilter['ADDRESSES'] as $addressTypeID => $addressFilter)
			{
				$sqlData['FROM'][] = EntityAddress::prepareFilterJoinSql(
					CCrmOwnerType::Contact,
					$addressTypeID,
					$addressFilter,
					$sender->GetTableAlias()
				);
			}
		}

		Tracking\UI\Filter::buildFilterAfterPrepareSql(
			$sqlData,
			$arFilter,
			\CCrmOwnerType::ResolveID(self::$TYPE_NAME),
			$sender->GetTableAlias()
		);

		if (isset($arFilter['OBSERVER_IDS']))
		{
			$observerIds = is_array($arFilter['OBSERVER_IDS']) ? $arFilter['OBSERVER_IDS'] : [];
			$observersFilter = CCrmEntityHelper::prepareObserversFieldFilter(
				CCrmOwnerType::Contact,
				$sender->GetTableAlias(),
				$observerIds
			);
			if (!empty($observersFilter))
			{
				$sqlData['WHERE'][] = $observersFilter;
			}
		}

		$result = array();
		if(!empty($sqlData['FROM']))
		{
			$result['FROM'] = implode(' ', $sqlData['FROM']);
		}
		if(!empty($sqlData['WHERE']))
		{
			$result['WHERE'] = implode(' AND ', $sqlData['WHERE']);
		}
		return !empty($result) ? $result : false;
	}

	public function Add(array &$arFields, $bUpdateSearch = true, $options = array())
	{
		global $DB;

		if(!is_array($options))
		{
			$options = array();
		}

		return $this->getCompatibilityAdapter()->performAdd($arFields, $options);
	}

	/**
	 * @deprecated
	 *
	 * Method will be removed soon
	 */
	static public function BuildEntityAttr($userID, $arAttr = array())
	{
		$userID = (int)$userID;
		$arResult = [];
		if(isset($arAttr['OPENED']) && $arAttr['OPENED'] == 'Y')
		{
			$arResult[] = 'O';
		}

		$userBasedEntityAttributes = Bitrix\Crm\Service\Container::getInstance()
			->getUserPermissions($userID)
			->getAttributesProvider()
			->getEntityAttributes()
		;

		return array_merge($arResult, $userBasedEntityAttributes);
	}

	static public function RebuildEntityAccessAttrs($IDs)
	{
		if(!is_array($IDs))
		{
			$IDs = array($IDs);
		}

		$dbResult = self::GetListEx(
			[],
			['@ID' => $IDs, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'ASSIGNED_BY_ID', 'OPENED', 'CATEGORY_ID', ]
		);

		if(!is_object($dbResult))
		{
			return;
		}

		while($fields = $dbResult->Fetch())
		{
			$ID = intval($fields['ID']);
			$assignedByID = isset($fields['ASSIGNED_BY_ID']) ? intval($fields['ASSIGNED_BY_ID']) : 0;
			if($assignedByID <= 0)
			{
				continue;
			}

			self::getPermissionsAdapter()
				->getAttributesHelper(
					self::getPermissionsAdapter()->getCurrentUserFromOptions(),
					Crm\Service\UserPermissions::OPERATION_READ
				)
				->setAssignedById($assignedByID)
				->setOpened($fields['OPENED'])
				->setCategoryId($fields['CATEGORY_ID'])
				->registerEntityAttributes($ID, $fields)
			;
		}
	}

	public function Update($ID, array &$arFields, $bCompare = true, $bUpdateSearch = true, $arOptions = array())
	{
		global $DB;

		$this->LAST_ERROR = '';
		$this->checkExceptions = [];

		$ID = (int) $ID;
		if(!is_array($arOptions))
		{
			$arOptions = array();
		}

		$arOptions['IS_COMPARE_ENABLED'] = $bCompare;

		return $this->getCompatibilityAdapter()->performUpdate($ID, $arFields, $arOptions);
	}

	public function Delete($ID, $arOptions = array())
	{
		global $DB, $APPLICATION;

		$ID = intval($ID);
		if(!is_array($arOptions))
		{
			$arOptions = array();
		}

		return $this->getCompatibilityAdapter()->performDelete($ID, $arOptions);
	}

	public static function ReleaseExternalResources(array $arFields)
	{
		$photoID = isset($arFields['PHOTO']) ? (int)$arFields['PHOTO'] : 0;
		if($photoID > 0)
		{
			\CFile::Delete($photoID);
		}
	}

	public function CheckFields(&$arFields, $ID = false, $options = array())
	{
		global $APPLICATION, $USER_FIELD_MANAGER;
		$this->LAST_ERROR = '';
		$this->checkExceptions = [];

		if (
			(
				$ID == false
				|| (isset($arFields['NAME']) && isset($arFields['LAST_NAME']))
			)
			&& (
				empty($arFields['NAME'])
				&& empty($arFields['LAST_NAME'])
			)
		)
		{
			$this->LAST_ERROR .= GetMessage('CRM_ERROR_REQUIRED_FIELDS') . "<br />";
		}

		if (isset($arFields['FM']) && is_array($arFields['FM']))
		{
			$CCrmFieldMulti = new CCrmFieldMulti();
			if (!$CCrmFieldMulti->CheckComplexFields($arFields['FM']))
				$this->LAST_ERROR .= $CCrmFieldMulti->LAST_ERROR;
		}

		if (isset($arFields['PHOTO']) && is_array($arFields['PHOTO']))
		{
			if (($strError = CFile::CheckFile($arFields['PHOTO'], 0, false, CFile::GetImageExtensions())) != '')
				$this->LAST_ERROR .= $strError."<br />";
		}

		if (isset($arFields['BIRTHDATE']) && $arFields['BIRTHDATE'] !== '' && !CheckDateTime($arFields['BIRTHDATE']))
		{
			$this->LAST_ERROR .=
				GetMessage(
					'CRM_ERROR_FIELD_INCORRECT',
					['%FIELD_NAME%' => self::GetFieldCaption('BIRTHDATE')]
				) . "<br />"
			;
		}

		if (!is_array($options))
		{
			$options = array();
		}

		$isRestoration = isset($options['IS_RESTORATION']) && $options['IS_RESTORATION'];
		if ($isRestoration)
		{
			$enableUserFieldCheck = false;
		}
		else
		{
			$enableUserFieldCheck = !(isset($options['DISABLE_USER_FIELD_CHECK'])
				&& $options['DISABLE_USER_FIELD_CHECK'] === true);
		}

		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Contact);
		if (isset($arFields['CATEGORY_ID']))
		{
			if (!$factory->isCategoryAvailable($arFields['CATEGORY_ID']))
			{
				if ($isRestoration)
				{
					$arFields['CATEGORY_ID'] = $factory->getDefaultCategory()->getId();
				}
				else
				{
					$this->LAST_ERROR .= GetMessage('CRM_ERROR_FIELD_INCORRECT',
							['%FIELD_NAME%' => self::GetFieldCaption('CATEGORY_ID')]) . "<br />";
				}
			}
		}

		if ($enableUserFieldCheck)
		{
			// We have to prepare field data before check (issue #22966)
			CCrmEntityHelper::NormalizeUserFields(
				$arFields,
				self::$sUFEntityID,
				$USER_FIELD_MANAGER,
				['IS_NEW' => ($ID == false)]
			);

			$enableRequiredUserFieldCheck = !(isset($options['DISABLE_REQUIRED_USER_FIELD_CHECK'])
				&& $options['DISABLE_REQUIRED_USER_FIELD_CHECK'] === true);

			$isUpdate = ($ID > 0);
			$fieldsToCheck = $arFields;
			if ($enableRequiredUserFieldCheck)
			{
				$requiredFields = Crm\Attribute\FieldAttributeManager::getRequiredFields(
					CCrmOwnerType::Contact,
					$ID,
					$fieldsToCheck,
					Crm\Attribute\FieldOrigin::UNDEFINED,
					isset($options['FIELD_CHECK_OPTIONS']) && is_array($options['FIELD_CHECK_OPTIONS'])
						? $options['FIELD_CHECK_OPTIONS']
						: []
				);

				$requiredSystemFields = $requiredFields[Crm\Attribute\FieldOrigin::SYSTEM] ?? [];

				if (!empty($requiredSystemFields))
				{
					$validator = new Crm\Entity\ContactValidator($ID, $fieldsToCheck);
					$validationErrors = array();
					foreach($requiredSystemFields as $fieldName)
					{
						if (
							!$isUpdate
							|| array_key_exists($fieldName, $fieldsToCheck)
							|| (
								isset($fieldsToCheck['FM'])
								&& is_array($fieldsToCheck['FM'])
								&& array_key_exists($fieldName, $fieldsToCheck['FM'])
							)
						)
						{
							$validator->checkFieldPresence($fieldName, $validationErrors);
						}
					}

					if (!empty($validationErrors))
					{
						$e = new CAdminException($validationErrors);
						$this->checkExceptions[] = $e;
						$this->LAST_ERROR .= $e->GetString();
					}
				}
			}

			if (isset($arFields['CATEGORY_ID']))
			{
				// category specified user fields
				$filteredUserFields = (new CCrmUserType($USER_FIELD_MANAGER, self::$sUFEntityID))
					->setOption(['categoryId' => $arFields['CATEGORY_ID']])
					->GetEntityFields($ID)
				;
			}

			if (
				!$USER_FIELD_MANAGER->CheckFields(
					self::$sUFEntityID,
					$ID,
					$fieldsToCheck,
					false,
					$enableRequiredUserFieldCheck,
					$requiredFields[Crm\Attribute\FieldOrigin::CUSTOM] ?? null,
					isset($filteredUserFields) ? array_keys($filteredUserFields) : null
				)
			)
			{
				$e = $APPLICATION->GetException();
				$this->checkExceptions[] = $e;
				$this->LAST_ERROR .= $e->GetString();
			}
		}

		// Temporary crutch.
		// This check will be removed when operations will be completely supported for contacts:
		$allowSetSystemFields = $options['ALLOW_SET_SYSTEM_FIELDS'] ?? false;
		if ($allowSetSystemFields)
		{
			$currentUserId = self::getPermissionsAdapter()->getCurrentUserFromOptions($options);

			$checkSystemFieldsResult = (new \Bitrix\Crm\Service\Operation\Import(
				$factory->createItem(),
				new \Bitrix\Crm\Service\Operation\Settings(Container::getInstance()->getContext()),
				$factory->getFieldsCollection()
			))->checkSystemFieldsValues([
				\Bitrix\Crm\Item::FIELD_NAME_CREATED_TIME => isset($arFields['DATE_CREATE'])
					? Main\Type\DateTime::createFromUserTime($arFields['DATE_CREATE'])
					: null
				,
				\Bitrix\Crm\Item::FIELD_NAME_UPDATED_TIME => isset($arFields['DATE_MODIFY'])
					? Main\Type\DateTime::createFromUserTime($arFields['DATE_MODIFY'])
					: null
				,
				\Bitrix\Crm\Item::FIELD_NAME_CREATED_BY =>
					(isset($arFields['CREATED_BY_ID']) && $arFields['CREATED_BY_ID'] != $currentUserId)
						? (int)$arFields['CREATED_BY_ID']
						: null
				,
				\Bitrix\Crm\Item::FIELD_NAME_UPDATED_BY =>
					(isset($arFields['MODIFY_BY_ID']) && $arFields['MODIFY_BY_ID'] != $currentUserId)
						? (int)$arFields['MODIFY_BY_ID']
						: null
				,
			]);
			if (!$checkSystemFieldsResult->isSuccess())
			{
				$this->LAST_ERROR .= implode(', ', $checkSystemFieldsResult->getErrorMessages());
			}
		}

		return $this->LAST_ERROR === '';
	}

	public function GetCheckExceptions()
	{
		return $this->checkExceptions;
	}

	/**
	 * @deprecated
	 * @see Bitrix\Crm\Binding\ContactCompanyTable::getCompanyContactIDs
	 * @param $companyID
	 * @return CDBResult
	 */
	public static function GetContactByCompanyID($companyID)
	{
		$companyID = (int)$companyID;
		return self::GetList(Array(), Array('COMPANY_ID' => ($companyID > 0 ? $companyID : -1)));
	}

	/**
	 * @deprecated
	 * @see Bitrix\Crm\Binding\ContactCompanyTable::bindContactIDs
	 * @param array $arIDs
	 * @param int $companyID
	 * @return bool
	 */
	public function UpdateCompanyID(array $arIDs, $companyID)
	{
		global $DB;

		if (!is_array($arIDs))
			return false;

		$arContactID = Array();
		foreach ($arIDs as $ID)
			$arContactID[] = (int) $ID;

		$companyID = (int) $companyID;

		if (!empty($arContactID))
			$DB->Query("UPDATE b_crm_contact SET COMPANY_ID = $companyID WHERE ID IN (".implode(',', $arContactID).")");

		return true;
	}

	public static function CompareFields(array $arFieldsOrig, array $arFieldsModif, array $arOptions = null)
	{
		if(!is_array($arOptions))
		{
			$arOptions = array();
		}

		$arMsg = Array();

		if (
			isset($arFieldsOrig['HONORIFIC'], $arFieldsModif['HONORIFIC'])
			&&
			$arFieldsOrig['HONORIFIC'] !== $arFieldsModif['HONORIFIC']
		)
			{
			$honorifics = CCrmStatus::GetStatusList('HONORIFIC');
			$arMsg[] = [
					'ENTITY_FIELD' => 'HONORIFIC',
					'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_HONORIFIC'),
				'EVENT_TEXT_1' => htmlspecialcharsbx(CrmCompareFieldsList($honorifics, $arFieldsOrig['HONORIFIC'])),
				'EVENT_TEXT_2' => htmlspecialcharsbx(CrmCompareFieldsList($honorifics, $arFieldsModif['HONORIFIC']))
			];
		}

		if (isset($arFieldsOrig['NAME']) && isset($arFieldsModif['NAME'])
			&& $arFieldsOrig['NAME'] != $arFieldsModif['NAME'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'NAME',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_NAME'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['NAME'])? $arFieldsOrig['NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['NAME'])? $arFieldsModif['NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		if (isset($arFieldsOrig['LAST_NAME']) && isset($arFieldsModif['LAST_NAME'])
			&& $arFieldsOrig['LAST_NAME'] != $arFieldsModif['LAST_NAME'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'LAST_NAME',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_LAST_NAME'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['LAST_NAME'])? $arFieldsOrig['LAST_NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['LAST_NAME'])? $arFieldsModif['LAST_NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		if (isset($arFieldsOrig['SECOND_NAME']) && isset($arFieldsModif['SECOND_NAME'])
			&& $arFieldsOrig['SECOND_NAME'] != $arFieldsModif['SECOND_NAME'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'SECOND_NAME',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_SECOND_NAME'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['SECOND_NAME'])? $arFieldsOrig['SECOND_NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['SECOND_NAME'])? $arFieldsModif['SECOND_NAME']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		if (isset($arFieldsOrig['FM']) && isset($arFieldsModif['FM']))
			$arMsg = array_merge($arMsg, CCrmFieldMulti::CompareFields($arFieldsOrig['FM'], $arFieldsModif['FM']));

		if (isset($arFieldsOrig['POST']) && isset($arFieldsModif['POST'])
			&& $arFieldsOrig['POST'] != $arFieldsModif['POST'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'POST',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_POST'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['POST'])? $arFieldsOrig['POST']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['POST'])? $arFieldsModif['POST']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		$addressOptions = array();
		if(isset($arOptions['ADDRESS_FIELDS']))
		{
			$addressOptions['FIELDS'] = $arOptions['ADDRESS_FIELDS'];
		}

		$arMsg = array_merge(
			$arMsg,
			ContactAddress::prepareChangeEvents(
				$arFieldsOrig,
				$arFieldsModif,
				ContactAddress::Primary,
				$addressOptions
			)
		);

		if (isset($arFieldsOrig['COMMENTS']) && isset($arFieldsModif['COMMENTS'])
			&& $arFieldsOrig['COMMENTS'] != $arFieldsModif['COMMENTS'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'COMMENTS',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_COMMENTS'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['COMMENTS'])? TextHelper::convertBbCodeToHtml($arFieldsOrig['COMMENTS']): GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['COMMENTS'])? TextHelper::convertBbCodeToHtml($arFieldsModif['COMMENTS']): GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		if(isset($arOptions['COMPANIES']) && is_array($arOptions['COMPANIES']))
		{
			$addedCompanyIDs = isset($arOptions['COMPANIES']['ADDED']) && is_array($arOptions['COMPANIES']['ADDED'])
				? $arOptions['COMPANIES']['ADDED'] : array();

			$removedCompanyIDs = isset($arOptions['COMPANIES']['REMOVED']) && is_array($arOptions['COMPANIES']['REMOVED'])
				? $arOptions['COMPANIES']['REMOVED'] : array();

			if(!empty($addedCompanyIDs) || !empty($removedCompanyIDs))
			{
				//region Preparation of company titles
				$dbResult = CCrmCompany::GetListEx(
					array(),
					array(
						'CHECK_PERMISSIONS' => 'N',
						'@ID' => array_merge($addedCompanyIDs, $removedCompanyIDs)
					),
					false,
					false,
					array('ID', 'TITLE')
				);

				$companyTitles = array();
				while ($ary = $dbResult->Fetch())
				{
					$companyTitles[$ary['ID']] = $ary['TITLE'];
				}
				//endregion
				if(count($addedCompanyIDs) <= 1 && count($removedCompanyIDs) <= 1)
				{
					//region Single binding mode
					$arMsg[] = Array(
						'ENTITY_FIELD' => 'COMPANY_ID',
						'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_COMPANY_ID'),
						'EVENT_TEXT_1' => CrmCompareFieldsList(
							$companyTitles,
							isset($removedCompanyIDs[0]) ? $removedCompanyIDs[0] : 0
						),
						'EVENT_TEXT_2' => CrmCompareFieldsList(
							$companyTitles,
							isset($addedCompanyIDs[0]) ? $addedCompanyIDs[0] : 0
						)
					);
					//endregion
				}
				else
				{
					//region Multiple binding mode
					//region Add companies event
					$texts = array();
					foreach($addedCompanyIDs as $companyID)
					{
						if(isset($companyTitles[$companyID]))
						{
							$texts[] = $companyTitles[$companyID];
						}
					}

					$arMsg[] = Array(
						'ENTITY_FIELD' => 'COMPANY_ID',
						'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_COMPANIES_ADDED'),
						'EVENT_TEXT_1' => implode(', ', $texts),
					);
					//endregion
					//region Remove companies event
					$texts = array();
					foreach($removedCompanyIDs as $companyID)
					{
						if(isset($companyTitles[$companyID]))
						{
							$texts[] = $companyTitles[$companyID];
						}
					}

					$arMsg[] = Array(
						'ENTITY_FIELD' => 'COMPANY_ID',
						'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_COMPANIES_REMOVED'),
						'EVENT_TEXT_1' => implode(', ', $texts),
					);
					//endregion
					//endregion
				}
			}
		}

		if (isset($arFieldsOrig['SOURCE_ID']) && isset($arFieldsModif['SOURCE_ID'])
			&& $arFieldsOrig['SOURCE_ID'] != $arFieldsModif['SOURCE_ID'])
		{
			$arStatus = CCrmStatus::GetStatusList('SOURCE');
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'SOURCE_ID',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_SOURCE_ID'),
				'EVENT_TEXT_1' => htmlspecialcharsbx(CrmCompareFieldsList($arStatus, $arFieldsOrig['SOURCE_ID'])),
				'EVENT_TEXT_2' => htmlspecialcharsbx(CrmCompareFieldsList($arStatus, $arFieldsModif['SOURCE_ID']))
			);
		}

		if (isset($arFieldsOrig['SOURCE_DESCRIPTION']) && isset($arFieldsModif['SOURCE_DESCRIPTION'])
			&& $arFieldsOrig['SOURCE_DESCRIPTION'] != $arFieldsModif['SOURCE_DESCRIPTION'])
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'SOURCE_DESCRIPTION',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_SOURCE_DESCRIPTION'),
				'EVENT_TEXT_1' => !empty($arFieldsOrig['SOURCE_DESCRIPTION'])? $arFieldsOrig['SOURCE_DESCRIPTION']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
				'EVENT_TEXT_2' => !empty($arFieldsModif['SOURCE_DESCRIPTION'])? $arFieldsModif['SOURCE_DESCRIPTION']: GetMessage('CRM_FIELD_COMPARE_EMPTY'),
			);

		if (isset($arFieldsOrig['TYPE_ID']) && isset($arFieldsModif['TYPE_ID'])
			&& $arFieldsOrig['TYPE_ID'] != $arFieldsModif['TYPE_ID'])
		{
			$arStatus = CCrmStatus::GetStatusList('CONTACT_TYPE');
			$arMsg[] = Array(
				'ENTITY_FIELD' => 'TYPE_ID',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_TYPE_ID'),
				'EVENT_TEXT_1' => htmlspecialcharsbx(CrmCompareFieldsList($arStatus, $arFieldsOrig['TYPE_ID'])),
				'EVENT_TEXT_2' => htmlspecialcharsbx(CrmCompareFieldsList($arStatus, $arFieldsModif['TYPE_ID']))
			);
		}

		if (isset($arFieldsOrig['ASSIGNED_BY_ID']) && isset($arFieldsModif['ASSIGNED_BY_ID'])
			&& (int)$arFieldsOrig['ASSIGNED_BY_ID'] != (int)$arFieldsModif['ASSIGNED_BY_ID'])
		{
			$arUser = Array();
			$dbUsers = CUser::GetList(
				'last_name', 'asc',
				array('ID' => implode('|', array(intval($arFieldsOrig['ASSIGNED_BY_ID']), intval($arFieldsModif['ASSIGNED_BY_ID'])))),
				array('FIELDS' => array('ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'LOGIN', 'TITLE', 'EMAIL'))
			);
			while ($arRes = $dbUsers->Fetch())
				$arUser[$arRes['ID']] = CUser::FormatName(CSite::GetNameFormat(false), $arRes);

			$arMsg[] = Array(
				'ENTITY_FIELD' => 'ASSIGNED_BY_ID',
				'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_ASSIGNED_BY_ID'),
				'EVENT_TEXT_1' => CrmCompareFieldsList($arUser, $arFieldsOrig['ASSIGNED_BY_ID']),
				'EVENT_TEXT_2' => CrmCompareFieldsList($arUser, $arFieldsModif['ASSIGNED_BY_ID'])
			);
		}

		if (isset($arFieldsModif['BIRTHDATE']))
		{
			$origBirthdate = isset($arFieldsOrig['BIRTHDATE']) ? $arFieldsOrig['BIRTHDATE'] : '';
			$modifBirthdate = isset($arFieldsModif['BIRTHDATE']) ? $arFieldsModif['BIRTHDATE'] : '';
			if($origBirthdate !== $modifBirthdate)
			{
				$arMsg[] = Array(
					'ENTITY_FIELD' => 'BIRTHDATE',
					'EVENT_NAME' => GetMessage('CRM_FIELD_COMPARE_BIRTHDATE'),
					'EVENT_TEXT_1' => $origBirthdate !== '' ? $origBirthdate : GetMessage('CRM_FIELD_COMPARE_EMPTY'),
					'EVENT_TEXT_2' => $modifBirthdate !== '' ? $modifBirthdate : GetMessage('CRM_FIELD_COMPARE_EMPTY')
				);
			}
		}

		return $arMsg;
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->entityType()->canAddItems()
	 */
	public static function CheckCreatePermission($userPermissions = null, int $categoryId = 0)
	{
		return self::getPermissionsAdapter()->canAddInCategory($categoryId, $userPermissions?->GetUserId());
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->item()->canUpdate()
	 */
	public static function CheckUpdatePermission($id, $userPermissions = null, ?int $categoryId = null)
	{
		return self::getPermissionsAdapter()->canUpdateInCategory((int)$id, $categoryId, $userPermissions?->GetUserId());
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->item()->canDelete()
	 */
	public static function CheckDeletePermission($id, $userPermissions = null, ?int $categoryId = null)
	{
		return self::getPermissionsAdapter()->canDeleteInCategory((int)$id, $categoryId, $userPermissions?->GetUserId());
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->item()->canRead()
	 */
	public static function CheckReadPermission($id = 0, $userPermissions = null, ?int $categoryId = null)
	{
		return self::getPermissionsAdapter()->canReadInCategory((int)$id, $categoryId, $userPermissions?->GetUserId());
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->entityType()->canImportItems()
	 */
	public static function CheckImportPermission($userPermissions = null, int $categoryId = 0)
	{
		return self::getPermissionsAdapter()->canImportInCategory($categoryId, $userPermissions?->GetUserId());
	}

	/**
	 * @deprecated
	 * @see \Bitrix\Crm\Service\Container::getInstance()->getUserPermissions()->entityType()->canExportItems()
	 */
	public static function CheckExportPermission($userPermissions = null, int $categoryId = 0)
	{
		return self::getPermissionsAdapter()->canExportInCategory($categoryId, $userPermissions?->GetUserId());
	}

	public static function PrepareFilter(&$arFilter, $arFilter2Logic = null)
	{
		if(!is_array($arFilter2Logic))
		{
			$arFilter2Logic = array('TITLE', 'NAME', 'LAST_NAME', 'SECOND_NAME', 'POST', 'ADDRESS', 'COMMENTS');
		}

		// converts data from filter
		if (isset($arFilter['FIND_list']) && !empty($arFilter['FIND']))
		{
			$arFilter[mb_strtoupper($arFilter['FIND_list'])] = $arFilter['FIND'];
			unset($arFilter['FIND_list'], $arFilter['FIND']);
		}

		static $arImmutableFilters = array(
			'FM', 'ID', 'COMPANY_ID', 'COMPANY_ID_value', 'ASSOCIATED_COMPANY_ID', 'ASSOCIATED_DEAL_ID', 'BINDING_OWNER',
			'ASSIGNED_BY_ID', 'ASSIGNED_BY_ID_value',
			'CREATED_BY_ID', 'CREATED_BY_ID_value',
			'MODIFY_BY_ID', 'MODIFY_BY_ID_value',
			'TYPE_ID', 'SOURCE_ID', 'WEBFORM_ID',
			'HAS_PHONE', 'HAS_EMAIL', 'HAS_IMOL', 'RQ',
			'SEARCH_CONTENT',
			'FILTER_ID', 'FILTER_APPLIED', 'PRESET_ID'
		);

		foreach ($arFilter as $k => $v)
		{
			if(in_array($k, $arImmutableFilters, true))
			{
				continue;
			}

			$arMatch = array();

			if($k === 'ORIGINATOR_ID')
			{
				// HACK: build filter by internal entities
				$arFilter['=ORIGINATOR_ID'] = $v !== '__INTERNAL' ? $v : null;
				unset($arFilter[$k]);
			}
			elseif($k === 'ADDRESS'
				|| $k === 'ADDRESS_2'
				|| $k === 'ADDRESS_CITY'
				|| $k === 'ADDRESS_REGION'
				|| $k === 'ADDRESS_PROVINCE'
				|| $k === 'ADDRESS_POSTAL_CODE'
				|| $k === 'ADDRESS_COUNTRY')
			{
				if(!isset($arFilter['ADDRESSES']))
				{
					$arFilter['ADDRESSES'] = array();
				}

				$addressTypeID = ContactAddress::resolveEntityFieldTypeID($k);
				if(!isset($arFilter['ADDRESSES'][$addressTypeID]))
				{
					$arFilter['ADDRESSES'][$addressTypeID] = array();
				}

				$n = ContactAddress::mapEntityField($k, $addressTypeID);
				$arFilter['ADDRESSES'][$addressTypeID][$n] = "{$v}%";
				unset($arFilter[$k]);
			}
			elseif (preg_match('/(.*)_from$/iu', $k, $arMatch))
			{
				if($v <> '')
				{
					$arFilter['>='.$arMatch[1]] = $v;
				}
				unset($arFilter[$k]);
			}
			elseif (preg_match('/(.*)_to$/iu', $k, $arMatch))
			{
				if($v <> '')
				{
					if (($arMatch[1] == 'DATE_CREATE' || $arMatch[1] == 'DATE_MODIFY') && !preg_match('/\d{1,2}:\d{1,2}(:\d{1,2})?$/u', $v))
					{
						$v = CCrmDateTimeHelper::SetMaxDayTime($v);
					}
					$arFilter['<='.$arMatch[1]] = $v;
				}
				unset($arFilter[$k]);
			}
			elseif (in_array($k, $arFilter2Logic))
			{
				// Bugfix #26956 - skip empty values in logical filter
				$v = trim($v);
				if($v !== '')
				{
					$arFilter['?'.$k] = $v;
				}
				unset($arFilter[$k]);
			}
			elseif (
				$k !== 'ID'
				&& $k !== 'LOGIC'
				&& $k !== '__INNER_FILTER'
				&& !str_starts_with($k, 'UF_')
				&& preg_match('/^[^\=\%\?\>\<]{1}/', $k) === 1
				&& str_ends_with($k, '_numsel') === false
			)
			{
				$arFilter['%'.$k] = $v;
				unset($arFilter[$k]);
			}
		}
	}

	public static function GetCount($arFilter)
	{
		$fields = self::GetFields();
		return CSqlUtil::GetCount(CCrmContact::TABLE_NAME, self::TABLE_ALIAS, $fields, $arFilter);
	}

	public static function PrepareFormattedName(array $arFields, $nameTemplate = '', $enabledEmptyNameStub = true)
	{
		if(!is_string($nameTemplate) || $nameTemplate === '')
		{
			$nameTemplate = \Bitrix\Crm\Format\PersonNameFormatter::getFormat();
		}

		static $honorificList = null;
		if($honorificList === null)
		{
			$honorificList = CCrmStatus::GetStatusList('HONORIFIC');
		}
		$honorific = '';
		$honorificID = isset($arFields['HONORIFIC']) ? $arFields['HONORIFIC'] : '';
		if($honorificID !== '' && isset($honorificList[$honorificID]))
		{
			$honorific = $honorificList[$honorificID];
		}

		return CUser::FormatName(
			$nameTemplate,
			array(
				'LOGIN' => '',
				'TITLE' => $honorific,
				'NAME' => isset($arFields['NAME']) ? $arFields['NAME'] : '',
				'SECOND_NAME' => isset($arFields['SECOND_NAME']) ? $arFields['SECOND_NAME'] : '',
				'LAST_NAME' => isset($arFields['LAST_NAME']) ? $arFields['LAST_NAME'] : ''
			),
			false,
			false,
			$enabledEmptyNameStub
		);
	}

	public static function RebuildDuplicateIndex($IDs)
	{
		if(!is_array($IDs))
		{
			$IDs = array($IDs);
		}

		$dbResult = self::GetListEx(
			array(),
			array('@ID' => $IDs, 'CHECK_PERMISSIONS' => 'N'),
			false,
			false,
			array('ID', 'NAME', 'SECOND_NAME', 'LAST_NAME', 'DATE_MODIFY')
		);

		if(!is_object($dbResult))
		{
			return;
		}

		$entityMultifields = DuplicateCommunicationCriterion::prepareBatchEntityMultifieldValues(
			CCrmOwnerType::Contact,
			$IDs
		);

		$duplicateCriterionRegistrar = DuplicateManager::getCriterionRegistrarForReindex(\CCrmOwnerType::Contact);

		while($fields = $dbResult->Fetch())
		{
			$ID = (int)$fields['ID'];
			$fields['FM'] = $entityMultifields[$ID] ?? null;

			$data =
				(new Crm\Integrity\CriterionRegistrar\Data())
					->setEntityTypeId(\CCrmOwnerType::Contact)
					->setEntityId($ID)
					->setCurrentFields($fields)
			;
			$duplicateCriterionRegistrar->register($data);

			DuplicateRequisiteCriterion::registerByEntity(CCrmOwnerType::Contact, $ID);

			DuplicateBankDetailCriterion::registerByEntity(CCrmOwnerType::Contact, $ID);
		}
	}

	public static function Rebind($ownerTypeID, $oldID, $newID)
	{
		global $DB;

		$ownerTypeID = (int)$ownerTypeID;
		$oldID = (int)$oldID;
		$newID = (int)$newID;
		$tableName = CCrmContact::TABLE_NAME;
		if($ownerTypeID === CCrmOwnerType::Company)
		{
			$DB->Query(
				"UPDATE {$tableName} SET COMPANY_ID = {$newID} WHERE COMPANY_ID = {$oldID}"
			);
		}
	}

	public static function ProcessLeadDeletion($leadID)
	{
		global $DB;
		$DB->Query("UPDATE b_crm_contact SET LEAD_ID = NULL WHERE LEAD_ID = {$leadID}");
	}

	public static function ProcessCompanyDeletion($companyID)
	{
		global $DB;
		$DB->Query("UPDATE b_crm_contact SET COMPANY_ID = NULL WHERE COMPANY_ID = {$companyID}");
	}

	public static function CreateRequisite($ID, $presetID)
	{
		if(!is_integer($ID))
		{
			$ID = (int)$ID;
		}

		if($ID <= 0)
		{
			throw new Main\ArgumentException('Must be greater than zero', 'ID');
		}

		if(!is_integer($presetID))
		{
			$presetID = (int)$presetID;
		}

		if($presetID <= 0)
		{
			throw new Main\ArgumentException('Must be greater than zero', 'presetID');
		}

		$externalID = "CONTACT_{$ID}";

		if(Crm\EntityRequisite::getByExternalId($externalID, array('ID')) !== null)
		{
			//Already exists
			return false;
		}

		$dbResult = self::GetListEx(
			array(),
			array('=ID' => $ID, 'CHECK_PERMISSIONS' => 'N')
		);

		$entityFields = $dbResult->Fetch();
		if(!is_array($entityFields))
		{
			throw new Main\ObjectNotFoundException("The contact with ID '{$ID}' is not found");
		}

		$presetEntity = new Crm\EntityPreset();
		$presetFields = $presetEntity->getById($presetID);
		if(!is_array($presetFields))
		{
			throw new Main\ObjectNotFoundException("The preset with ID '{$presetID}' is not found");
		}

		$fieldInfos = $presetEntity->settingsGetFields(
			is_array($presetFields['SETTINGS']) ? $presetFields['SETTINGS'] : array()
		);

		$fullName = self::GetFullName($entityFields);

		$requisiteFields = array();
		foreach($fieldInfos as $fieldInfo)
		{
			$fieldName = isset($fieldInfo['FIELD_NAME']) ? $fieldInfo['FIELD_NAME'] : '';
			if($fieldName === Crm\EntityRequisite::PERSON_FIRST_NAME)
			{
				if(isset($entityFields['NAME']) && $entityFields['NAME'] !== '')
				{
					$requisiteFields[Crm\EntityRequisite::PERSON_FIRST_NAME] = $entityFields['NAME'];
				}
			}
			elseif($fieldName === Crm\EntityRequisite::PERSON_SECOND_NAME)
			{
				if(isset($entityFields['SECOND_NAME']) && $entityFields['SECOND_NAME'] !== '')
				{
					$requisiteFields[Crm\EntityRequisite::PERSON_SECOND_NAME] = $entityFields['SECOND_NAME'];
				}
			}
			elseif($fieldName === Crm\EntityRequisite::PERSON_LAST_NAME)
			{
				if(isset($entityFields['LAST_NAME']) && $entityFields['LAST_NAME'] !== '')
				{
					$requisiteFields[Crm\EntityRequisite::PERSON_LAST_NAME] = $entityFields['LAST_NAME'];
				}
			}
			elseif($fieldName === Crm\EntityRequisite::PERSON_FULL_NAME)
			{
				if($fullName !== '')
				{
					$requisiteFields[Crm\EntityRequisite::PERSON_FULL_NAME] = $fullName;
				}
			}
			elseif($fieldName === Crm\EntityRequisite::ADDRESS)
			{
				$requisiteFields[Crm\EntityRequisite::ADDRESS] = array(
					EntityAddressType::Primary =>
						ContactAddress::mapEntityFields(
							$entityFields,
							array('TYPE_ID' => EntityAddressType::Primary, 'SKIP_EMPTY' => true)
						)
				);
			}
		}

		if(empty($requisiteFields))
		{
			return false;
		}

		$requisiteFields['NAME'] = $fullName !== '' ? $fullName : $externalID;
		$requisiteFields['PRESET_ID'] = $presetID;
		$requisiteFields['ACTIVE'] = 'Y';
		$requisiteFields['ENTITY_TYPE_ID'] = CCrmOwnerType::Contact;
		$requisiteFields['ENTITY_ID'] = $ID;
		$requisiteFields['XML_ID'] = $externalID;

		$requisiteEntity = new Crm\EntityRequisite();
		return $requisiteEntity->add($requisiteFields)->isSuccess();
	}

	public static function SynchronizeMultifieldMarkers($sourceID, array $fields = null)
	{
		global $DB;

		if($sourceID <= 0)
		{
			return;
		}

		if($fields === null)
		{
			$dbResult = self::GetListEx(
				array(),
				array('=ID' => $sourceID, 'CHECK_PERMISSIONS' => 'N'),
				false,
				false,
				array('ID', 'HAS_EMAIL', 'HAS_PHONE', 'HAS_IMOL')
			);

			if(is_object($dbResult))
			{
				$fields = $dbResult->Fetch();
			}
		}

		if($fields === null)
		{
			return;
		}

		$multifields = isset($fields['FM']) && is_array($fields['FM']) ? $fields['FM'] : null;
		if($multifields === null)
		{
			$multifields = DuplicateCommunicationCriterion::prepareEntityMultifieldValues(
				CCrmOwnerType::Contact,
				$sourceID
			);
		}

		$hasEmail = CCrmFieldMulti::HasValues($multifields, CCrmFieldMulti::EMAIL) ? 'Y' : 'N';
		$hasPhone = CCrmFieldMulti::HasValues($multifields, CCrmFieldMulti::PHONE) ? 'Y' : 'N';
		$hasImol = CCrmFieldMulti::HasImolValues($multifields) ? 'Y' : 'N';

		if(!isset($fields['HAS_EMAIL']) || $fields['HAS_EMAIL'] !== $hasEmail ||
			!isset($fields['HAS_PHONE']) || $fields['HAS_PHONE'] !== $hasPhone ||
			!isset($fields['HAS_IMOL']) || $fields['HAS_IMOL'] !== $hasImol
		)
		{
			$DB->Query("UPDATE b_crm_contact SET HAS_EMAIL = '{$hasEmail}', HAS_PHONE = '{$hasPhone}', HAS_IMOL = '{$hasImol}' WHERE ID = {$sourceID}");
		}
	}

	public static function GetDefaultName()
	{
		return GetMessage('CRM_CONTACT_UNNAMED');
	}

	public static function GetDefaultTitleTemplate()
	{
		return GetMessage('CRM_CONTACT_DEFAULT_TITLE_TEMPLATE');
	}

	public static function GetDefaultTitle($number = '')
	{
		return GetMessage('CRM_CONTACT_DEFAULT_TITLE_TEMPLATE', array('%NUMBER%' => $number));
	}

	/**
	 * Indicates if a contact has default name
	 *
	 * @param string $name
	 * @return bool
	 */
	public static function isDefaultName(string $name): bool
	{
		$defaultNames = [
			self::GetDefaultName(),
			Loc::getMessage('CRM_WEBFORM_ENTITY_FIELD_NAME_CONTACT_TEMPLATE')
		];
		if (in_array($name, $defaultNames, true))
		{
			return true;
		}

		return mb_strpos($name, self::GetDefaultTitle('')) === 0;
	}

	public function getLastError(): string
	{
		return (string)$this->LAST_ERROR;
	}
}
