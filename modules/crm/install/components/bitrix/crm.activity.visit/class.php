<?php

use Bitrix\Crm;
use Bitrix\Crm\Activity;
use Bitrix\Crm\Component\ComponentError;
use Bitrix\Crm\Integration;
use Bitrix\Crm\Settings\LeadSettings;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

Loc::loadMessages(__FILE__);
Crm\Service\Container::getInstance()->getLocalization()->loadMessages();

class CrmActivityVisitComponent extends \CBitrixComponent implements Main\Errorable
{
	const ACTION_VIEW = 'VIEW';
	const ACTION_EDIT = 'EDIT';
	const MAX_IMAGE_SIZE = 2097152;
	const MAX_CHECKED_BINDINGS = 10;

	public function __construct($component = null)
	{
		parent::__construct($component);
		$this->errorCollection = new Main\ErrorCollection();
	}

	public function executeComponent()
	{
		$action = $this->getAction();
		switch ($action)
		{
			case self::ACTION_EDIT:
				if (!Crm\Restriction\RestrictionManager::getVisitRestriction()->hasPermission())
				{
					$this->errorCollection->setError(new Main\Error(Loc::getMessage('CRM_FEATURE_RESTRICTION_ERROR'), 'crm-tariff-lock'));
					return $this->includeComponentTemplate('error');
				}
				return $this->executeEditAction();
				break;
			default:
				return $this->executeViewAction();
				break;
		}
	}

	public static function saveActivity($fields, $userId, $siteId)
	{
		if (!Crm\Restriction\RestrictionManager::getVisitRestriction()->hasPermission())
		{
			$result = new Main\Result();
			$result->addError(new Main\Error(Loc::getMessage('CRM_FEATURE_RESTRICTION_ERROR')));

			return $result;
		}

		$accessibleBindings = self::resolveAccessibleBindings($fields, (int)$userId);

		$result = new Main\Result();
		if ($accessibleBindings === null)
		{
			$result->addError(new Main\Error(ComponentError::getMessage(ComponentError::ENTITY_NOT_FOUND)));
			return $result;
		}

		$startTime = Main\Type\DateTime::createFromTimestamp($fields['CREATE_TIMESTAMP']);
		$recordFileDescription = Main\Application::getInstance()->getContext()->getRequest()->getFile('RECORD');
		if(!$recordFileDescription)
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_ACTIVITY_NO_RECORD_ERROR')));
			return $result;
		}
		$recordFileDescription['name'] = 'record_' . $startTime->format('Y_m_d_h_i_s') . '.mp3';
		$recordFileDescription['MODULE_ID'] = 'crm';
		$fileId = CFile::SaveFile($recordFileDescription, 'crm');
		
		if(!$fileId)
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_ACTIVITY_NO_RECORD_ERROR')));
			return $result;
		}

		$bindings = $accessibleBindings['CHECKED'];
		$owner = $accessibleBindings['OWNER'];
		if ($owner !== null)
		{
			$ownerTypeId = $owner['OWNER_TYPE_ID'];
			$ownerId = $owner['OWNER_ID'];
		}
		else
		{
			$leadId = self::createLead(array(
				'START_TIME' => $startTime,
				'USER_ID' => $userId,
			));

			if($leadId === false)
			{
				$result->addError(new Main\Error(Loc::getMessage('CRM_ACTIVITY_VISIT_LEAD_CREATE_ERROR')));
				return $result;
			}
			$ownerTypeId = CCrmOwnerType::Lead;
			$ownerId = (int)$leadId;
			array_unshift($bindings, array('OWNER_TYPE_ID' => $ownerTypeId, 'OWNER_ID' => $ownerId));
		}

		if($fields['SAVE_PHOTO'] === 'Y' && $ownerTypeId === CCrmOwnerType::Contact && isset($_FILES['IMAGE']))
		{
			$imageDescriptor = $_FILES['IMAGE'];
			$imageDescriptor['name'] = 'contact_'.$ownerId.'_image.png';
			if(CFile::CheckImageFile($imageDescriptor) === null)
			{
				$imageId = CFile::SaveFile($imageDescriptor, 'crm');
				if ($imageId > 0)
				{
					$contact = new CCrmContact();
					$contactFields = array(
						'PHOTO' => $imageId
					);
					$contact->Update($ownerId, $contactFields);
				}
			}
		}

		$providerParams = array(
			'RECORD_LENGTH' => (int)$fields['RECORD_LENGTH']
		);

		$activityFields = array(
			'PROVIDER_ID' => Activity\Provider\Visit::PROVIDER_ID,
			'PROVIDER_TYPE_ID' => Activity\Provider\Visit::TYPE_VISIT,
			'START_TIME' => $startTime,
			'COMPLETED' => 'Y',
			'PRIORITY' => CCrmActivityPriority::Medium,
			'SUBJECT' => Loc::getMessage('CRM_ACTIVITY_VISIT_SUBJECT'),
			'DESCRIPTION' => Loc::getMessage(
				'CRM_ACTIVITY_VISIT_DESCRIPTION',
				array(
					'#DATE#' => CCrmComponentHelper::TrimDateTimeString($startTime),
			)),
			'DESCRIPTION_TYPE' => CCrmContentType::PlainText,
			'LOCATION' => '',
			'NOTIFY_TYPE' => CCrmActivityNotifyType::None,
			'BINDINGS' => $bindings,
			'SETTINGS' => array(),
			'AUTHOR_ID' => $userId,
			'RESPONSIBLE_ID' => $userId,
			'STORAGE_TYPE_ID' => Integration\StorageType::File,
			'STORAGE_ELEMENT_IDS' => array($fileId),
			'PROVIDER_PARAMS' => $providerParams
		);
		
		$activityId = CCrmActivity::Add($activityFields, true, true, array('REGISTER_SONET_EVENT' => true));
		if($activityId > 0)
		{
			$communications = array(
				array(
					'ID' => 0,
					'ENTITY_ID' => $ownerId,
					'ENTITY_TYPE_ID' => $ownerTypeId
				)
			);

			CCrmActivity::SaveCommunications($activityId, $communications, $activityFields, true, false);
		}

		if($activityId > 0)
		{
			$result->setData(array(
				'ACTIVITY_ID' => $activityId
			));

			//Execute automation trigger
			\Bitrix\Crm\Automation\Trigger\VisitTrigger::execute($bindings, array(
				'ACTIVITY_ID' => $activityId,
				'initiatorUserId' => (int)$userId,
			));
		}
		else
		{
			$result->addError(new Main\Error(Loc::getMessage('CRM_ACTIVITY_CREATE_ERROR')));
		}
		return $result;
	}

	/**
	 * The bindings of the request once the acting user may attach an activity to every one of them, or null
	 * when the request has to be refused. Called in saveActivity right after the tariff restriction and before
	 * any side effect: a refusal has to precede the record file, the photo of the contact and the activity
	 * itself.
	 *
	 * The owner is read strictly - a partly filled pair of fields, an unknown type and an id that is not a
	 * plain positive number are refused - while the elements of the sets keep the cast to an integer they
	 * have always been read with.
	 *
	 * @param array|mixed $fields
	 * @return array|null array('OWNER' => array|null, 'CHECKED' => array): an absent owner is the branch that
	 *         creates a new lead, and CHECKED holds only the pairs the user supplied
	 */
	private static function resolveAccessibleBindings($fields, int $userId): ?array
	{
		$rawType = $fields['OWNER_ENTITY_TYPE'] ?? null;
		$rawId = $fields['OWNER_ENTITY_ID'] ?? null;

		$hasType = !is_array($rawType) && trim((string)$rawType) !== '';
		$ownerIdText = (is_scalar($rawId) && !is_bool($rawId)) ? trim((string)$rawId) : '';
		$hasId = preg_match('/^[0-9]+$/D', $ownerIdText) === 1 && (int)$ownerIdText > 0;

		if ($hasType !== $hasId)
		{
			return null;
		}

		$owner = null;
		if ($hasType)
		{
			$ownerTypeId = CCrmOwnerType::ResolveID($rawType);
			if ($ownerTypeId <= 0)
			{
				return null;
			}

			$owner = array('OWNER_TYPE_ID' => $ownerTypeId, 'OWNER_ID' => (int)$ownerIdText);
		}

		$checked = self::collectRequestedBindings($owner, $fields);
		if (count($checked) > self::MAX_CHECKED_BINDINGS)
		{
			return null;
		}

		$activityPermissions = Crm\Service\Container::getInstance()->getUserPermissions($userId)->activity();
		foreach ($checked as $binding)
		{
			if (!$activityPermissions->canAddToOwner($binding['OWNER_TYPE_ID'], $binding['OWNER_ID']))
			{
				return null;
			}
		}

		return array('OWNER' => $owner, 'CHECKED' => $checked);
	}

	/**
	 * The pairs of the request in the order the activity needs them - the owner, then CREATED_DEALS, then
	 * CREATED_INVOICES - each pair kept once, at its first occurrence. The order matters beyond the set: the
	 * core takes the owner of the activity from the last binding that is neither a contact nor a company.
	 *
	 * @param array|mixed $fields
	 */
	private static function collectRequestedBindings(?array $owner, $fields): array
	{
		$bindings = array();
		if ($owner !== null)
		{
			$bindings[] = $owner;
		}

		$requestedIds = array(
			CCrmOwnerType::Deal => $fields['CREATED_DEALS'] ?? null,
			CCrmOwnerType::Invoice => $fields['CREATED_INVOICES'] ?? null,
		);
		foreach ($requestedIds as $ownerTypeId => $rawIds)
		{
			if (!is_array($rawIds))
			{
				continue;
			}

			foreach ($rawIds as $rawId)
			{
				$ownerId = (int)$rawId;
				if ($ownerId > 0)
				{
					$bindings[] = array('OWNER_TYPE_ID' => $ownerTypeId, 'OWNER_ID' => $ownerId);
				}
			}
		}

		return self::withoutRepeatedBindings($bindings);
	}

	private static function withoutRepeatedBindings(array $bindings): array
	{
		$unique = array();
		foreach ($bindings as $binding)
		{
			$key = $binding['OWNER_TYPE_ID'] . '_' . $binding['OWNER_ID'];
			if (!isset($unique[$key]))
			{
				$unique[$key] = $binding;
			}
		}

		return array_values($unique);
	}

	protected function executeEditAction()
	{
		$this->arResult = $this->prepareDataForEdit();
		$this->includeComponentTemplate('edit');
		return $this->arResult;
	}

	protected function executeViewAction()
	{
		$this->arResult = $this->prepareDataForView();
		$this->includeComponentTemplate('view');
		return $this->arResult;
	}

	/**
	 * @return array
	 */
	protected function prepareDataForEdit()
	{
		$result = array();
		$userPermissions = CCrmPerms::GetUserPermissions($this->getCurrentUserId());

		$result['CAN_CREATE_CONTACT'] = CCrmContact::CheckCreatePermission($userPermissions);
		$result['CAN_CREATE_LEAD'] = CCrmLead::CheckCreatePermission($userPermissions);
		$result['CAN_CREATE_DEAL'] = CCrmDeal::CheckCreatePermission($userPermissions);
		$result['CAN_CREATE_INVOICE'] = CCrmInvoice::CheckCreatePermission($userPermissions) && Crm\Settings\InvoiceSettings::getCurrent()->isOldInvoicesEnabled();
		$result['DEAL'] = '';
		if(isset($this->arParams['ENTITY_TYPE']) && isset($this->arParams['ENTITY_ID']))
		{
			if($this->arParams['ENTITY_TYPE'] === CCrmOwnerType::DealName)
			{
				$deal = CCrmDeal::GetByID($this->arParams['ENTITY_ID'], true);
				if($deal)
				{
					if($deal['CONTACT_ID'] > 0)
					{
						$result['OWNER_ENTITY_TYPE'] = CCrmOwnerType::ContactName;
						$result['OWNER_ENTITY_ID'] = (int)$deal['CONTACT_ID'];
					}
					else if ($deal['COMPANY_ID'] > 0)
					{
						$result['OWNER_ENTITY_TYPE'] = CCrmOwnerType::CompanyName;
						$result['OWNER_ENTITY_ID'] = (int)$deal['COMPANY_ID'];
					}
					else if ($deal['LEAD_ID'] > 0)
					{
						$result['OWNER_ENTITY_TYPE'] = CCrmOwnerType::LeadName;
						$result['OWNER_ENTITY_ID'] = (int)$deal['LEAD_ID'];
					}
					$result['DEAL'] = (int)$deal['ID'];
				}
			}
			else
			{
				$result['OWNER_ENTITY_TYPE'] = (string)$this->arParams['ENTITY_TYPE'];
				$result['OWNER_ENTITY_ID'] = (int)$this->arParams['ENTITY_ID'];
			}

			$result['SHOW_ENTITY_SELECTOR'] = false;
		}
		else
		{
			$result['SHOW_ENTITY_SELECTOR'] = true;
		}

		if ($result['CAN_CREATE_LEAD'])
		{
			$result['CREATE_LEAD_CONTEXT'] = 'visit_'.$this->randString();
			$result['CREATE_LEAD_URL'] = CCrmUrlUtil::AddUrlParams(
				CCrmOwnerType::GetEditUrl(CCrmOwnerType::Lead, 0),
				array('external_context' => $result['CREATE_LEAD_CONTEXT'])
			);
		}

		if ($result['CAN_CREATE_CONTACT'])
		{
			$result['CREATE_CONTACT_CONTEXT'] = 'visit_'.$this->randString();
			$result['CREATE_CONTACT_URL'] = CCrmUrlUtil::AddUrlParams(
				CCrmOwnerType::GetEditUrl(CCrmOwnerType::Contact, 0),
				array('external_context' => $result['CREATE_CONTACT_CONTEXT'])
			);
		}

		if($result['CAN_CREATE_DEAL'])
		{
			$result['CREATE_DEAL_CONTEXT'] = 'visit_'.$this->randString();
			$result['CREATE_DEAL_URL'] = CCrmUrlUtil::AddUrlParams(
				CCrmOwnerType::GetEditUrl(CCrmOwnerType::Deal, 0),
				array('external_context' => $result['CREATE_DEAL_CONTEXT'])
			);
		}

		if($result['CAN_CREATE_INVOICE'])
		{
			$result['CREATE_INVOICE_CONTEXT'] = 'visit_'.$this->randString();
			$result['CREATE_INVOICE_URL'] = CCrmUrlUtil::AddUrlParams(
				CCrmOwnerType::GetEditUrl(CCrmOwnerType::Invoice, 0),
				array('external_context' => $result['CREATE_INVOICE_CONTEXT'])
			);
		}

		$result['FACEID_ENABLED'] = false;

		return $result;
	}

	/**
	 * @return array
	 */
	protected function prepareDataForView()
	{
		$activity = $this->arParams['~ACTIVITY'];
		$result = array(
			'ACTIVITY' => $activity,
			'RECORDS' => array(),
			'PHOTO' => array(),
			'STORAGE_ELEMENTS' => array()
		);

		if (is_array($activity["STORAGE_ELEMENT_IDS"]) && count($activity["STORAGE_ELEMENT_IDS"]) > 0)
		{
			$mediaExtensions = array("flv", "mp3", "mp4", "vp6", "aac");
			foreach($activity["STORAGE_ELEMENT_IDS"] as $elementID)
			{
				$info = Bitrix\Crm\Integration\StorageManager::getFileInfo(
					$elementID, $activity["STORAGE_TYPE_ID"],
					false,
					array('OWNER_TYPE_ID' => CCrmOwnerType::Activity, 'OWNER_ID' => $activity['ID'])
				);
				if(is_array($info) && in_array(GetFileExtension(mb_strtolower($info["NAME"])), $mediaExtensions))
				{
					$recordUrl = CCrmUrlUtil::ToAbsoluteUrl($info["VIEW_URL"]);
					if($activity["STORAGE_TYPE_ID"] == CCrmActivityStorageType::WebDav)
					{
						//Hacks for flv player
						if(mb_substr($recordUrl, -1) !== "/")
						{
							$recordUrl .= "/";
						}
						$recordUrl .= !empty($info["NAME"]) ? $info["NAME"] : "dummy.flv";
					}
					$result["RECORDS"][] = array(
						"URL" =>$recordUrl,
						"NAME" => $info["NAME"],
						"INFO" => $info
					);
				}
				elseif (!empty($activity['PROVIDER_PARAMS']['tracker_photo']) && $elementID == $activity['PROVIDER_PARAMS']['tracker_photo'])
				{
					// it is a photo
					$photoUrl = CCrmUrlUtil::ToAbsoluteUrl($info["VIEW_URL"]);

					if($activity["STORAGE_TYPE_ID"] == CCrmActivityStorageType::WebDav)
					{
						//Hacks for flv player
						if(mb_substr($photoUrl, -1) !== "/")
						{
							$photoUrl .= "/";
						}
						$photoUrl .= !empty($info["NAME"]) ? $info["NAME"] : "";
					}

					$result["PHOTO"] = array(
						"URL" =>$photoUrl,
						"NAME" => $info["NAME"],
						"INFO" => $info
					);
				}

				$result["STORAGE_ELEMENTS"][] = $info;
			}
		}
		
		return $result;
	}
	
	protected function getCurrentUserId()
	{
		global $USER;
		return $USER->GetID();
	}

	protected function getAction()
	{
		return isset($this->arParams['ACTION']) ? $this->arParams['ACTION'] : self::ACTION_VIEW;
	}

	/**
	 * Creates new lead. Returns id of the lead or false in case of errors.
	 * @param $params
	 * @return int|false 
	 */
	protected static function createLead($params)
	{
		$leadFields = array(
			'TITLE' => Loc::getMessage('CRM_ACTIVITY_VISIT_LEAD_TITLE', array('#DATE#' => $params['START_TIME'])),
			'OPENED' => LeadSettings::getCurrent()->getOpenedFlag() ? 'Y' : 'N',
		);

		$crmLead = new CCrmLead();
		$leadId = $crmLead->Add($leadFields, true, array(
			'CURRENT_USER' => $params['USER_ID'],
			'DISABLE_USER_FIELD_CHECK' => true
		));

		return $leadId;
	}

	/**
	 * @param string $code
	 * @return Main\Error|null
	 */
	public function getErrorByCode($code)
	{
		return $this->errorCollection->getErrorByCode($code);
	}

	/**
	 * @return Main\Error[]
	 */
	public function getErrors()
	{
		return $this->errorCollection->toArray();
	}
}
