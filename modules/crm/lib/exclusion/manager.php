<?php
namespace Bitrix\Crm\Exclusion;

use Bitrix\Crm;
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class Manager
{
	public static function isEntityTypeSupported(int $entityTypeId): bool
	{
		return Store::isEntityTypeSupported($entityTypeId);
	}

	public static function checkCreatePermission()
	{
		return Access::current()->canWrite();
	}

	public static function excludeEntity($entityTypeID, $entityID, $checkPermissions = true, array $params = array())
	{
		global $APPLICATION;

		if(!is_int($entityTypeID))
		{
			$entityTypeID = (int)$entityTypeID;
		}

		if(!\CCrmOwnerType::IsDefined($entityTypeID))
		{
			throw new Main\ArgumentOutOfRangeException('entityTypeID',
				\CCrmOwnerType::FirstOwnerType,
				\CCrmOwnerType::LastOwnerType
			);
		}

		if(!is_int($entityID))
		{
			$entityID = (int)$entityID;
		}

		if($entityID <= 0)
		{
			throw new Main\ArgumentException('Must be greater than zero', 'entityID');
		}

		if ($checkPermissions && !Crm\Service\Container::getInstance()->getUserPermissions()->item()->canRead($entityTypeID, $entityID))
		{
			throw new Main\AccessDeniedException(Loc::getMessage('CRM_PERMISSION_DENIED'));
		}

		if($checkPermissions && !self::checkCreatePermission())
		{
			throw new Main\AccessDeniedException(Loc::getMessage('CRM_PERMISSION_DENIED'));
		}

		$comment = $params['COMMENT'] ?? null;

		Crm\Exclusion\Store::addFromEntity($entityTypeID, $entityID, $comment);

		if($entityTypeID === \CCrmOwnerType::Deal)
		{
			$entityFields = Crm\DealTable::getList(
				array(
					'select' => array('ID', 'COMPANY_ID', 'CONTACT_ID'),
					'filter' => array('=ID' => $entityID),
				)
			)->fetch();

			//Concurrency check
			if(!is_array($entityFields))
			{
				return;
			}

			$companyID = isset($entityFields['COMPANY_ID']) ? (int)$entityFields['COMPANY_ID'] : 0;
			$contactID = isset($entityFields['CONTACT_ID']) ? (int)$entityFields['CONTACT_ID'] : 0;

			if($companyID > 0
				&& (!$checkPermissions || \CCrmCompany::CheckDeletePermission($companyID))
			)
			{
				$hasExtraDeals = Crm\DealTable::getCount(
					array('=COMPANY_ID' => $companyID, '!=ID' => $entityID)
				) > 0;
				$hasExtraContacts = false;

				if(!$hasExtraDeals)
				{
					if($contactID === 0)
					{
						$hasExtraContacts = Crm\Binding\ContactCompanyTable::getCount(
							array('=COMPANY_ID' => $companyID)
						) > 0;
					}
					else
					{
						$hasExtraContacts = Crm\Binding\ContactCompanyTable::getCount(
							array('=COMPANY_ID' => $companyID, '!=CONTACT_ID' => $contactID)
						) > 0;
					}
				}

				if(!$hasExtraDeals && !$hasExtraContacts)
				{
					$companyEntity = new \CCrmCompany(false);
					if(!$companyEntity->Delete($companyID))
					{
						/** @var \CApplicationException $ex */
						$ex = $APPLICATION->GetException();
						if($ex)
						{
							$error = ($ex instanceof \CApplicationException)
								? $ex->GetString() : Loc::getMessage('CRM_EXCLUSION_COMPANY_DELETION_ERROR');
							throw new Main\ObjectException($error);
						}
					}
				}
			}

			if($contactID > 0
				&& (!$checkPermissions || \CCrmContact::CheckDeletePermission($contactID))
			)
			{
				$hasExtraDeals = Crm\Binding\DealContactTable::getCount(
					array('=CONTACT_ID' => $contactID, '!=DEAL_ID' => $entityID)
				) > 0;
				$hasExtraCompanies = false;

				if(!$hasExtraDeals)
				{
					if($companyID === 0)
					{
						$hasExtraCompanies = Crm\Binding\ContactCompanyTable::getCount(
							array('=CONTACT_ID' => $contactID)
						) > 0;
					}
					else
					{
						$hasExtraCompanies = Crm\Binding\ContactCompanyTable::getCount(
							array('=CONTACT_ID' => $contactID, '!=COMPANY_ID' => $companyID)
						) > 0;
					}
				}

				if(!$hasExtraDeals && !$hasExtraCompanies)
				{
					$contactEntity = new \CCrmContact(false);
					if(!$contactEntity->Delete($contactID))
					{
						/** @var \CApplicationException $ex */
						$ex = $APPLICATION->GetException();
						if($ex)
						{
							$error = ($ex instanceof \CApplicationException)
								? $ex->GetString() : Loc::getMessage('CRM_EXCLUSION_CONTACT_DELETION_ERROR');
							throw new Main\ObjectException($error);
						}
					}
				}
			}

			$dealEntity = new \CCrmDeal($checkPermissions);
			if(!$dealEntity->Delete($entityID))
			{
				/** @var \CApplicationException $ex */
				$ex = $APPLICATION->GetException();
				if($ex)
				{
					$error = ($ex instanceof \CApplicationException)
						? $ex->GetString() : Loc::getMessage('CRM_EXCLUSION_DEAL_DELETION_ERROR');
					throw new Main\ObjectException($error);
				}
			}
		}

		if($entityTypeID === \CCrmOwnerType::Lead)
		{
			$leadEntity = new \CCrmLead($checkPermissions);
			if(!$leadEntity->Delete($entityID))
			{
				/** @var \CApplicationException $ex */
				$ex = $APPLICATION->GetException();
				if($ex)
				{
					$error = ($ex instanceof \CApplicationException)
						? $ex->GetString() : Loc::getMessage('CRM_EXCLUSION_LEAD_DELETION_ERROR');
					throw new Main\ObjectException($error);
				}
			}
		}
	}
}
