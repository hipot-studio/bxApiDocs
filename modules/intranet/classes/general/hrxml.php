<?

IncludeModuleLangFile(__FILE__);

class CUserHRXMLImport
{

	var $next_step = false;
	var $files_dir = false;
	
	var $bUpdateOnly = false;
	
	var $arParams = array();
	
	var $DEPARTMENTS_IBLOCK_ID = 0;
	var $ABSENCE_IBLOCK_ID = 0;
	var $VACANCY_IBLOCK_ID = 0;
	var $STATE_HISTORY_IBLOCK_ID = 0;
	var $STRUCTURE_ROOT = 0;
	var $errors = array();
	var $warnings = array();
	var $PersonIDSchemeName = '';

	var $arStateMapping = array(
		'ACCEPTED' => 'New',
		'MOVED' => 'Modified',
		'FIRED' => 'Closed',
	);
	var $cnt = 0;
	private $_users;

	function CheckIBlock($id, $type)
	{
		$bError = false;
		if (!empty($id) && !empty($type))
		{
			$dbRes = CIBlock::GetList(
				array(),
				array(
					'TYPE' => $type,
					'ID' => $id,
				)
			);

			if (intval($dbRes->SelectedRowsCount()) < 1)
			{
				$bError = true;
			}
		}
		else
		{
			$bError = true;
		}

		if ($bError)
			return false;

		return true;
	}

	function Init($arParams = array())
	{
		CModule::IncludeModule('iblock');
		$this->__user = new CUser();
		$this->__element = new CIBlockElement();

		$this->arParams = $arParams;
		$this->arParams['DEFAULT_EMAIL'] = trim($this->arParams['DEFAULT_EMAIL']);
		if (empty($this->arParams['DEFAULT_EMAIL']))
		{
			$this->arParams['DEFAULT_EMAIL'] = COption::GetOptionString(
				'main', 'email_from',
				"admin@".$_SERVER['SERVER_NAME']
			);
		}
		if (empty($this->arParams['DEFAULT_EMAIL']))
		{
			$this->errors[] = GetMessage('ERROR_DEFAULT_EMAIL_MISSING');
			return false;
		}

		$this->PersonIDSchemeName = COption::GetOptionString("intranet", "import_PersonIDSchemeName", "");

		if (empty($this->PersonIDSchemeName))
		{
			$this->errors[] = GetMessage('ERROR_PersonIDSchemeName_MISSING');
			return false;
		}

		$this->arSectionCache = &$this->next_step['_TEMPORARY']['DEPARTMENTS'];

		$this->DEPARTMENTS_IBLOCK_ID = $this->arParams['DEPARTMENTS_IBLOCK_ID'];
		$this->ABSENCE_IBLOCK_ID = $this->arParams['ABSENCE_IBLOCK_ID'];
		$this->STATE_HISTORY_IBLOCK_ID = $this->arParams['STATE_HISTORY_IBLOCK_ID'];
		$this->VACANCY_IBLOCK_ID = $this->arParams['VACANCY_IBLOCK_ID'];

		$def_group = COption::GetOptionString("main", "new_user_registration_def_group", "");

		if ($def_group != "")
			$this->arUserGroups = explode(",", $def_group);
		
		return true;
	}

	function ImportData($xml)
	{
		if (isset ($xml->DataArea->PositionOpening))
		{
			if (!$this->CheckIBlock($this->VACANCY_IBLOCK_ID, $this->arParams['IBLOCK_TYPE_VACANCY']))
			{
				$this->errors[] = GetMessage('IBLOCK_XML2_USER_ERROR_IBLOCK_MISSING');
				return false;
			}
			$this->ImportSyncPositionOpening($xml->DataArea->PositionOpening);
		}
		elseif (isset ($xml->DataArea->StaffingAssignment))
		{
			if (!$this->CheckIBlock(array($this->DEPARTMENTS_IBLOCK_ID, $this->STATE_HISTORY_IBLOCK_ID), $this->arParams['IBLOCK_TYPE']))
			{
				$this->errors[] = GetMessage('IBLOCK_XML2_USER_ERROR_IBLOCK_MISSING');
				return false;
			}
			$this->_users = $this->LoadUserCodes();
			$this->ImportSyncStaffingAssignment($xml->DataArea);
		}
		elseif (isset ($xml->DataArea->TimeCard))
		{
			if (!$this->CheckIBlock($this->ABSENCE_IBLOCK_ID, $this->arParams['IBLOCK_TYPE']))
			{
				$this->errors[] = GetMessage('IBLOCK_XML2_USER_ERROR_IBLOCK_MISSING');
				return false;
			}
			$this->_users = $this->LoadUserCodes();
			$this->ImportSyncTimeCard($xml->DataArea);
		}
		elseif (isset ($xml->DataArea->IndicativeData))
		{
			if (!$this->CheckIBlock($this->DEPARTMENTS_IBLOCK_ID, $this->arParams['IBLOCK_TYPE']))
			{
				$this->errors[] = GetMessage('IBLOCK_XML2_USER_ERROR_IBLOCK_MISSING');
				return false;
			}
			$this->_users = array();
			$this->ImportSyncIndicativeData($xml->DataArea);
		}
		elseif (isset ($xml->DataArea->OrganizationChart))
		{
			if (!$this->CheckIBlock($this->DEPARTMENTS_IBLOCK_ID, $this->arParams['IBLOCK_TYPE']))
			{
				$this->errors[] = GetMessage('IBLOCK_XML2_USER_ERROR_IBLOCK_MISSING');
				return false;
			}
			$this->ImportOrganizationChart($xml->DataArea);
		}
	}

	function PrepareAnswer($applicationArea)
	{
		$answer = array(
			'ApplicationArea' => array(
				'CreationDateTime' => date ('Y-m-dTH:i:s'),
			),
			'DataArea'=>array(
				'Confirm' => array(
					'OriginalApplicationArea' => array(
						'Sender' => array(
						),
						'CreationDateTime' => $applicationArea->CreationDateTime,
						'BODID' => $applicationArea->BODID,
					),
				),
				'BOD' => array(),
			),
		);

		if (isset($applicationArea->Sender->LogicalID))
			$answer['DataArea']['Confirm']['OriginalApplicationArea']['Sender']['LogicalID'] = $applicationArea->Sender->LogicalID;
		if (isset($applicationArea->Sender->ComponentID))
			$answer['DataArea']['Confirm']['OriginalApplicationArea']['Sender']['ComponentID'] = $applicationArea->Sender->ComponentID;
		if (isset($applicationArea->Sender->TaskID))
			$answer['DataArea']['Confirm']['OriginalApplicationArea']['Sender']['TaskID'] = $applicationArea->Sender->TaskID;
		if (isset($applicationArea->Sender->ConfirmationCode))
			$answer['DataArea']['Confirm']['OriginalApplicationArea']['Sender']['ConfirmationCode'] = $applicationArea->Sender->ConfirmationCode;
		if (!empty($this->errors))
		{
			$answer['DataArea']['BOD']['BODFailureMessage'] = array(
				'ErrorProcessMessage' => array(),
			);
			foreach ($this->errors as $message)
			{
				$message = htmlspecialcharsbx($message);
				$answer['DataArea']['BOD']['BODFailureMessage']['ErrorProcessMessage'][] = array('Description'=>$message);
			}
			if (!empty($this->warnings))
			{
				$answer['DataArea']['BOD']['BODFailureMessage']['WarningProcessMessage'] = array();
				foreach ($this->warnings as $message) {
					$message = htmlspecialcharsbx($message);
					$answer['DataArea']['BOD']['BODFailureMessage']['WarningProcessMessage'][] = array('Description'=>$message);
				}
			}
		}
		else
		{
			if (!empty($this->warnings))
			{
				$answer['DataArea']['BOD']['BODSuccessMessage']['WarningProcessMessage'] = array();
				foreach ($this->warnings as $message)
				{
					$message = htmlspecialcharsbx($message);
					$answer['DataArea']['BOD']['BODSuccessMessage']['WarningProcessMessage'][] = array('Description'=>$message);
				}
			}
			else
			{
				$answer['DataArea']['BOD']['BODSuccessMessage'] = array();
			}
		}
		$converter = new CArray2XML('ConfirmBOD >> xmlns="http://www.openapplications.org/oagis/9" xmlns:xs="http://www.w3.org/2001/XMLSchema" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" releaseID="3.2" systemEnvironmentCode="Production" languageCode="ru-RU"');
		return $converter->Convert($answer);
	}

	private function ImportSyncIndicativeData($xml)
	{
		$arUsers = array();
		define('INTR_SKIP_EVENT_ADD', 'Y');
		$arSections = array();
		$this->STRUCTURE_ROOT = $this->GetStructureRoot();
		$departmentRepository = \Bitrix\Intranet\Service\ServiceContainer::getInstance()
			->departmentRepository();
		foreach ($xml->IndicativeData as $data)
		{
			if (!isset ($data->IndicativePersonDossier))
				continue;
			$data = $data->IndicativePersonDossier;
			$terminated = false;
			if (isset($data->IndicativeEmployment->EmploymentLifecycle))
			{
				$employmentLifecycle = $data->IndicativeEmployment->EmploymentLifecycle;
				if (isset($employmentLifecycle->Termination->LastWorkedDate))
				{
					if ($employmentLifecycle->Termination->LastWorkedDate < date('Y-m-d'))
						$terminated = true;
				}
			}
			$tempUserData = array();
			$PersonID = false;
			if (isset ($data->IndicativePerson->PersonID))
				$PersonID = $this->GetGUID($data->IndicativePerson->PersonID);

			$EmployeeID = false;
			if (isset ($data->IndicativeEmployee->EmployeeID))
				$EmployeeID = $this->GetGUID($data->IndicativeEmployee->EmployeeID);

			if (isset($data->IndicativePerson->PersonName))
			{
				$arName = $this->GetCurrentName($data->IndicativePerson);
				if (isset($arName->LegalName))
					$tempUserData['NAME'] = (string)$arName->LegalName;
				if (isset($arName->FamilyName))
					$tempUserData['LAST_NAME'] = (string)$arName->FamilyName;
				if (isset($arName->MiddleName))
					$tempUserData['SECOND_NAME'] = (string)$arName->MiddleName;
			}

			if (isset($data->IndicativePerson->GenderCode))
				$tempUserData['PERSONAL_GENDER'] = mb_substr((string)$data->IndicativePerson->GenderCode, 0, 1);

			if (isset($data->IndicativePerson->BirthDate))
			{
				$tempUserData['PERSONAL_BIRTHDAY'] = explode('-', (string) $data->IndicativePerson->BirthDate);
				$tempUserData['PERSONAL_BIRTHDAY'] = $tempUserData['PERSONAL_BIRTHDAY'][2].'.'.$tempUserData['PERSONAL_BIRTHDAY'][1].'.'.$tempUserData['PERSONAL_BIRTHDAY'][0];
			}

			if (isset($data->IndicativePerson->Communication))
			{
				foreach ($data->IndicativePerson->Communication as $key => $value)
				{
					if ($value->ChannelCode == 'Email')
							$tempUserData['EMAIL'] = (string) $value->URI;
				}
			}

			if (isset($data->IndicativeDeployment->Job))
				$tempUserData['WORK_POSITION'] = (string)$data->IndicativeDeployment->Job->JobTitle;
			else
				$tempUserData['WORK_POSITION'] = '';

			$lastDepartmentId = $this->STRUCTURE_ROOT;
			$lastDepartment = null;

			if (isset($data->IndicativeDeployment->DeploymentOrganization))
			{
				if (isset($data->IndicativeDeployment->DeploymentOrganization->OrganizationIdentifiers))
				{
					$xmlDepartments = $data->IndicativeDeployment->DeploymentOrganization->OrganizationIdentifiers;
					foreach ($xmlDepartments as $xmlDepartment)
					{
						$department = new \Bitrix\Intranet\Entity\Department(
							(string)$xmlDepartment->OrganizationName,
							parentId: (int)$lastDepartmentId,
							xmlId: $this->GetGUID($xmlDepartment->OrganizationID),
						);
						if (!array_key_exists($department->getXmlId(), $arSections))
						{
							$extDepartment = $departmentRepository->findAllByXmlId($department->getXmlId())->first();

							try
							{
								if ($extDepartment)
								{
									$department->setId($extDepartment->getId());
								}
								else
								{
									$department->setIsActive(true);
								}
								$department = $departmentRepository->save($department);
								$arSections[$department->getXmlId()] = $department->getId();
							}
							catch (\Exception $exception)
							{
								$GLOBALS['APPLICATION']->ThrowException($exception->getMessage());
							}
						}
						$lastDepartmentId = $arSections[$department->getXmlId()];
						$lastDepartment = $department;
					}
				}
			}


			if (array_key_exists($PersonID, $this->_users))
			{
				$arUser = $this->_users[$PersonID];
			}
			else
			{
				$rsUser = CUser::GetList(
					"ID",
					"desc",
					array('XML_ID' => $PersonID),
					array('FIELDS' => array('ID', 'ACTIVE'), 'SELECT' => array('UF_DEPARTMENT', 'UF_WORK_BINDING'))
				);
				if ($arUser = $rsUser->fetch())
				{
					$arUser['UF_WORK_BINDING'] = unserialize($arUser['UF_WORK_BINDING'], ["allowed_classes" => false]);
				}
				else
				{
					$arUser = array(
						'ACTIVE' => 'Y',
						'UF_1C' => 'Y',
						'XML_ID' => $PersonID,
						'UF_WORK_BINDING' => array(
							'PERSON_ID' => $PersonID,
							'DEPARTMENTS' => array(
								$EmployeeID => array(
									'DEPARTMENT' => $lastDepartment?->getXmlId() ?? '', //$lastXML_ID,
									'JOB' => base64_encode($tempUserData['WORK_POSITION']),
								),
							),
						),
					);
				}
			}
			if ($terminated)
			{
				if (array_key_exists($EmployeeID, $arUser['UF_WORK_BINDING']['DEPARTMENTS']))
					unset ($arUser['UF_WORK_BINDING']['DEPARTMENTS'][$EmployeeID]);
			}
			else
			{
				$arUser['UF_WORK_BINDING']['DEPARTMENTS'][$EmployeeID] = array(
					'DEPARTMENT' => $lastDepartment?->getXmlId() ?? '',
					'JOB' => base64_encode($tempUserData['WORK_POSITION']),
				);
			}
			if (isset($tempUserData['PERSONAL_BIRTHDAY']))
				$arUser['PERSONAL_BIRTHDAY'] = $tempUserData['PERSONAL_BIRTHDAY'];
			if (isset($tempUserData['PERSONAL_GENDER']))
				$arUser['PERSONAL_GENDER'] = $tempUserData['PERSONAL_GENDER'];
			if (isset($tempUserData['NAME']))
				$arUser['NAME'] = $tempUserData['NAME'];
			if (isset($tempUserData['LAST_NAME']))
				$arUser['LAST_NAME'] = $tempUserData['LAST_NAME'];
			if (isset($tempUserData['SECOND_NAME']))
				$arUser['SECOND_NAME'] = $tempUserData['SECOND_NAME'];
			if (isset($tempUserData['EMAIL']))
				$arUser['EMAIL'] = $tempUserData['EMAIL'];

			$arUser['UF_DEPARTMENT'] = array();
			$arUser['WORK_POSITION'] = array();
			foreach ($arUser['UF_WORK_BINDING']['DEPARTMENTS'] as $value)
			{
				if (empty($value['DEPARTMENT']) || $value['DEPARTMENT'] == ($lastDepartment?->getXmlId() ?? ''))
				{
					if (!in_array($lastDepartmentId, $arUser['UF_DEPARTMENT']))
						$arUser['UF_DEPARTMENT'][] = $lastDepartmentId;
				}
				elseif (array_key_exists($value['DEPARTMENT'], $arSections))
				{
					if (!in_array($arSections[$value['DEPARTMENT']], $arUser['UF_DEPARTMENT']))
						$arUser['UF_DEPARTMENT'][] = $arSections[$value['DEPARTMENT']];
				}
				else
				{
					$department = $departmentRepository->findAllByXmlId($value['DEPARTMENT'])->first();
					if ($department)
					{
						if (!in_array($department->getId(), $arUser['UF_DEPARTMENT']))
						{
							$arUser['UF_DEPARTMENT'] = $department->getId();
						}
						$arSections[$value['DEPARTMENT']] = $department->getId();
					}
				}
				$value['JOB'] = base64_decode($value['JOB']);
				if (!empty($value['JOB']) && !in_array($value['JOB'], $arUser['WORK_POSITION']))
					$arUser['WORK_POSITION'][] = $value['JOB'];
			}
			$arUser['WORK_POSITION'] = implode(' / ', $arUser['WORK_POSITION']);

			if ($terminated && empty($arUser['UF_DEPARTMENT']))
				$arUser['ACTIVE'] = 'N';

			$this->_users[$PersonID] = $arUser;
			$arUser['UF_WORK_BINDING'] = serialize($arUser['UF_WORK_BINDING']);
			$arUsers[] = $arUser;
		}

		foreach ($arUsers as $user)
		{
			$counter=array();
			$this->ImportUser($user, $counter);
		}

		return true;
	}

	private function ImportSyncPositionOpening($xml)
	{
		foreach ($xml as $profile)
		{
			$el = new CIBlockElement;
			$arFields = array(
				'IBLOCK_ID' => $this->VACANCY_IBLOCK_ID,
				'XML_ID' => (string) $profile->PositionProfile->PositionID,
			);
			$rsVacancy = CIBlockElement::GetList(array(), $arFields, false, false, array('ID'));
			$arFields['ACTIVE_FROM'] = date(
				'd.m.Y H:i:s',
				strtotime((string) $profile->PositionProfile->PositionPeriod->StartDate->FormattedDateTime)
			);
			$arFields['NAME'] = (string) $profile->PositionProfile->PositionTitle;
			$arFields['ACTIVE'] = 'Y';
			if (isset($profile->PositionProfile->PositionFormattedDescription)) {
				$arFields['DETAIL_TEXT_TYPE'] = 'html';
				$arFields['DETAIL_TEXT'] = '';
				foreach($profile->PositionProfile->PositionFormattedDescription as $description){
					$arFields['DETAIL_TEXT'] .= '<b>' . ((string) $description->Name) . '</b><br>';
					$arFields['DETAIL_TEXT'] .= '<pre>' . ((string) $description->Content) . '</pre><br>';
				}
			}
			if ($arVacancy = $rsVacancy->GetNext())
			{
				$el->Update($arVacancy['ID'], $arFields);
				if (!empty($el->LAST_ERROR))
				{
					$this->warnings[] = GetMessage('IBLOCK_HR_NOT_UPDATED_VACANCY').' "'
						.$arFields['NAME'].'"('.$arFields['XML_ID'].')'."\r\n".$el->LAST_ERROR;
				}
			}
			else
			{
				$el->Add($arFields);
				if (!empty($el->LAST_ERROR))
				{
					$this->warnings[] = GetMessage('IBLOCK_HR_NOT_ADD_VACANCY').' "'
						.$arFields['NAME'].'"('.$arFields['XML_ID'].')'."\r\n".$el->LAST_ERROR;
				}
			}
		}

		return true;
	}

	private function ImportOrganizationChart($xml)
	{
		$arSections = array();
		$this->STRUCTURE_ROOT = $this->GetStructureRoot();
		$departmentRepository = \Bitrix\Intranet\Service\ServiceContainer::getInstance()
			->departmentRepository();
		$xmlId = '';
		foreach ($xml->OrganizationChart as $value)
		{
			$departmentUnit = new \Bitrix\Intranet\Entity\Department(
				$value->OrganizationUnit->OrganizationUnitName,
				parentId: (int) $this->STRUCTURE_ROOT,
				xmlId: (string) $value->OrganizationUnit->OrganizationUnitID
			);
			if (!array_key_exists($departmentUnit->getXmlId(), $arSections))
			{
				$department = $departmentRepository->findAllByXmlId($value['DEPARTMENT'])->first();
				if ($department)
				{
					$arSections[$departmentUnit->getXmlId()] = $department->getId();
				}
			}

			if (isset($value->OrganizationUnit->ParentOrganizationUnit))
			{
				$xmlId = (string) $value->OrganizationUnit->ParentOrganizationUnit->OrganizationUnitID;
				if (!array_key_exists($xmlId, $arSections) || empty($arSections[$xmlId]))
				{
					$department = $departmentRepository->findAllByXmlId($value['DEPARTMENT'])->first();
					if ($department)
					{
						$arSections[$xmlId] = $department->getId();
					}
					else
					{
						$department = $departmentRepository->save(
							new \Bitrix\Intranet\Entity\Department(
								(string) $value->OrganizationUnit->ParentOrganizationUnit->OrganizationName,
								parentId: (int) $this->STRUCTURE_ROOT,
								xmlId: $xmlId
							)
						);
						$departmentId = $department->getId();
						if ($departmentId)
						{
							$arSections[$xmlId] = $departmentId;
						}
					}
				}
				$departmentUnit->setParentId(isset($arSections[$xmlId]) ? (int)$arSections[$xmlId] : null);
			}

			try
			{
				$willUpdate = array_key_exists($departmentUnit->getXmlId(), $arSections);
				if ($willUpdate)
				{
					$departmentUnit->setId((int)$arSections[$departmentUnit->getXmlId()]);
				}

				$departmentUnit = $departmentRepository->save($departmentUnit);
				$arSections[$departmentUnit->getXmlId()] = $departmentUnit->getId();
			}
			catch (\Exception $exception)
			{
				$message = $willUpdate
					? GetMessage('IBLOCK_HR_NOT_UPDATED_DEPARTMENT')
					: GetMessage('IBLOCK_HR_NOT_ADD_DEPARTMENT');
				$this->warnings[] = $message.' "'
					.$departmentUnit->getName().'"('.$xmlId.')'."\r\n".$exception->getMessage();
			}
		}
	}

	private function ImportSyncStaffingAssignment($xml)
	{
		$db_enum_list = CIBlockProperty::GetPropertyEnum(
			'STATE',
			array(),
			array('IBLOCK_ID' => $this->STATE_HISTORY_IBLOCK_ID)
		);
		$arStates = array();
		while($ar_enum_list = $db_enum_list->GetNext())
		{
			$arStates[$this->arStateMapping[$ar_enum_list['XML_ID']]] = array(
				'ID' => $ar_enum_list['ID'],
				'NAME' => $ar_enum_list['VALUE'],
			);
		}
		$obElement = &$this->__element;
		$arAssignmentID = array();
		$departmentRepository = \Bitrix\Intranet\Service\ServiceContainer::getInstance()
			->departmentRepository();
		foreach ($xml->StaffingAssignment as $assignment)
		{
			if (isset($assignment->ResourcePerson))
			{
				$personID = $this->GetPersonGUID($assignment->ResourcePerson->PersonID);
				$arUserFields = array('XML_ID' => $this->FindUserByPersonID($personID));
				if (empty($arUserFields['XML_ID']))
				{
					$this->warnings[] = str_replace('#ID#', $personID, GetMessage('IBLOCK_HR_USER_NOT_FOUND'));
					continue;
				}
				$arHistoryPROP = array(
					'STATE' => $arStates[(string)$assignment->StaffingAssignmentStatusCode]['ID'],
				);

				if (isset($assignment->ResourceDeployment->StaffingJob->JobTitle))
					$arHistoryPROP['POST'] = $arUserFields['WORK_POSITION'];

				$rsUser = CUser::GetList(
					"ID",
					"desc",
					array('XML_ID' => $arUserFields['XML_ID']),
					array('FIELDS' => array('ID', 'ACTIVE'))
				);
				if ($arUser = $rsUser->fetch())
					$arHistoryPROP['USER'] = $arUser['ID'];
				else
					continue;

				if (isset(
					$assignment
						->ResourceDeployment
						->DeploymentOrganization
						->OrganizationIdentifiers
						->OrganizationID
				))
				{
					$xmlId = $this->GetGUID(
						$assignment->ResourceDeployment
						->DeploymentOrganization
						->OrganizationIdentifiers
						->OrganizationID
					);
					$department = $departmentRepository->findAllByXmlId($xmlId)->first();
					if ($department)
					{
						$arHistoryPROP['DEPARTMENT'] = $department->getId();
					}
					else
					{
						$arHistoryPROP['DEPARTMENT'] = '';
					}
				}
				$arHistoryRecord = array('IBLOCK_ID' => $this->STATE_HISTORY_IBLOCK_ID);
				$arRecord = false;
				if (isset($assignment->StaffingReferenceIDs->StaffingAssignmentID))
				{
					$arHistoryRecord['XML_ID'] = (string) $assignment->StaffingReferenceIDs->StaffingAssignmentID;
					if (!array_key_exists($arHistoryRecord['XML_ID'], $arAssignmentID))
					{
						$rsRecord = CIBlockElement::GetList(array(), $arHistoryRecord, false, false, array('ID'));
						while ($arRecord = $rsRecord->Fetch())
							CIBlockElement::Delete($arRecord['ID']);
					}
				}

				$arHistoryRecord['ACTIVE'] = 'Y';
				$arHistoryRecord['PROPERTY_VALUES'] = $arHistoryPROP;
				$arHistoryRecord['NAME'] = ' - '.(string) $assignment->ResourcePerson->PersonName->FormattedName;
				$arHistoryRecord['ACTIVE_FROM'] = explode('-', (string) $assignment->AssignmentAvailability->StartDate->FormattedDateTime);
				$arHistoryRecord['ACTIVE_FROM'] = $arHistoryRecord['ACTIVE_FROM'][2].'.'
					.$arHistoryRecord['ACTIVE_FROM'][1].'.'.$arHistoryRecord['ACTIVE_FROM'][0];
				$arHistoryRecord['NAME'] = $arStates[(string) $assignment->StaffingAssignmentStatusCode]['NAME']
					.$arHistoryRecord['NAME'];

				$result = $obElement->Add($arHistoryRecord);
				$arAssignmentID[$arHistoryRecord['XML_ID']] = $result;
			}
		}

		return true;
	}

	private function ImportSyncTimeCard($xml)
	{
		$absenceTypePropertyId = 0;
		$rsProperty = CIBlockProperty::GetList(
			array(),
			array('IBLOCK_ID' => $this->ABSENCE_IBLOCK_ID, 'CODE' => 'ABSENCE_TYPE')
		);
		if ($arProperty = $rsProperty->Fetch())
			$absenceTypePropertyId = $arProperty['ID'];

		if ($absenceTypePropertyId > 0)
		{
			$db_enum_list = CIBlockProperty::GetPropertyEnum(
				$absenceTypePropertyId,
				array(),
				array('IBLOCK_ID' => $this->ABSENCE_IBLOCK_ID)
			);
			$arStates = array();
			while ($ar_enum_list = $db_enum_list->GetNext())
			{
				$arStates[$ar_enum_list['XML_ID']] = array(
					'ID' => $ar_enum_list['ID'],
					'NAME' => $ar_enum_list['VALUE']
				);
			}
		}
		$obElement = &$this->__element;

		foreach ($xml->TimeCard as $timeCard)
		{
			if (isset($timeCard->ReportedResource))
			{
				$personID = $this->GetPersonGUID($timeCard->ReportedResource->SpecifiedPerson->PersonID);
				$arUserFields = array(
					'XML_ID' => $this->FindUserByPersonID($personID),
					'ACTIVE' => 'Y',
				);
				if (empty($arUserFields['XML_ID']))
				{
					$this->warnings[] = str_replace('#ID#', $personID, GetMessage('IBLOCK_HR_USER_NOT_FOUND'));
					continue;
				}

				$arTimePROP = array();
				$rsUser = CUser::GetList(
					"ID",
					"desc",
					$arUserFields,
					array('FIELDS' => array('ID'))
				);
				if ($arUser = $rsUser->fetch())
					$arTimePROP['USER'] = $arUser['ID'];
				else
					continue;

				foreach ($timeCard->TimeCardReportedItem as $timeItem)
				{
					if ($absenceTypePropertyId > 0 && isset($timeItem->TimeInterval->TimeIntervalTypeCode))
					{
						$timeIntervalTypeCode = (string) $timeItem->TimeInterval->TimeIntervalTypeCode;
						if (array_key_exists($timeIntervalTypeCode, $arStates))
						{
							$arTimePROP['ABSENCE_TYPE'] = $arStates[$timeIntervalTypeCode]['ID'];
						}
						else
						{
							$ibpenum = new CIBlockPropertyEnum;
							$attr = $timeItem->TimeInterval->TimeIntervalTypeCode->attributes();
							$attr = (array) $attr;
							$attr = array_shift($attr);

							$arFields = array(
								'PROPERTY_ID' => $absenceTypePropertyId,
								'VALUE' => $attr['name'],
								'XML_ID' => $timeIntervalTypeCode,
							);
							if ($PropID = $ibpenum->Add($arFields))
							{
								$arTimePROP['ABSENCE_TYPE'] = $PropID;
								$arStates[$timeIntervalTypeCode] = array(
									'ID' => $PropID,
									'NAME' => $arFields['VALUE'],
								);
							}
						}
					}
					$arTimeRecord = array(
						'ACTIVE' => 'Y',
						'IBLOCK_ID' => $this->ABSENCE_IBLOCK_ID,
						'PROPERTY_VALUES' => $arTimePROP,
						'ACTIVE_FROM' => explode('-', (string) $timeItem->TimeInterval->FreeFormEffectivePeriod->StartDate->FormattedDateTime),
						'ACTIVE_TO' => explode('-', (string) $timeItem->TimeInterval->FreeFormEffectivePeriod->EndDate->FormattedDateTime),
					);
					$arTimeRecord['ACTIVE_FROM'] = $arTimeRecord['ACTIVE_FROM'][2].'.'
						.$arTimeRecord['ACTIVE_FROM'][1].'.'
						.$arTimeRecord['ACTIVE_FROM'][0];
					$arTimeRecord['ACTIVE_TO'] = $arTimeRecord['ACTIVE_TO'][2].'.'
						.$arTimeRecord['ACTIVE_TO'][1].'.'
						.$arTimeRecord['ACTIVE_TO'][0];
					$arTimeRecord['NAME'] = $arStates[$timeIntervalTypeCode]['NAME'];
					$result = $obElement->Add($arTimeRecord);
				}
			}
		}

		return true;
	}

	private function ImportUser($arUser, &$counter)
	{
		static $USER_COUNTER = null;
		$obUser = &$this->__user;

		if (null == $USER_COUNTER)
		{
			$dbRes = $GLOBALS['DB']->Query('SELECT MAX(ID) M FROM b_user');
			$ar = $dbRes->Fetch();
			$USER_COUNTER = $ar['M'];
		}
		
		if (isset($arUser['ID']))
			$CURRENT_USER = $arUser['ID'];
		else
			$CURRENT_USER = 0;

		if ($CURRENT_USER > 0)
		{
			unset ($arUser['ID']);
			foreach ($arUser as $key => $value)
			{
				if ($key !== 'ACTIVE' && $key !== 'XML_ID' && !in_array($key, $this->arParams['UPDATE_PROPERTIES']))
					unset($arUser[$key]);
			}
			// update existing user
			if ($res = $obUser->Update($CURRENT_USER, $arUser))
				$counter[$arUser['ACTIVE'] == 'Y' ? 'UPD' : 'DEA']++;
		}
		else
		{
			// EMAIL, LOGIN and PASSWORD fields
			$USER_COUNTER++;
			
			$arUser['LOGIN'] = '';
			if ($this->arParams['LDAP_ID_PROPERTY_XML_ID'] && $this->arParams['LDAP_SERVER'])
			{
				$arUser['LOGIN'] = $arUser[$this->CalcPropertyFieldName($this->arParams['LDAP_ID_PROPERTY_XML_ID'])];
				if ($arUser['LOGIN'])
					$arUser['EXTERNAL_AUTH_ID'] = 'LDAP#'.$this->arParams['LDAP_SERVER'];
			}
			
			if (!$arUser['LOGIN'] && $this->arParams['LOGIN_TEMPLATE'])
				$arUser['LOGIN'] = str_replace('#', $USER_COUNTER, $this->arParams['LOGIN_TEMPLATE']);
			if (!$arUser['LOGIN'])
				$arUser['LOGIN'] = 'user_' . $USER_COUNTER;

			if (!$arUser['EXTERNAL_AUTH_ID'])
			{
				if (!$arUser['PASSWORD'])
				{
					$arUser['PASSWORD'] = $arUser['CONFIRM_PASSWORD'] = RandString(
						$this->arParams['PASSWORD_LENGTH'] ? $this->arParams['PASSWORD_LENGTH'] : 7
					);
				}
			}

			$bEmailExists = !empty($arUser['EMAIL']);
			if (empty($arUser['EMAIL']))
			{
				if (!empty($this->arParams['DEFAULT_EMAIL']))
					$arUser['EMAIL'] = $this->arParams['DEFAULT_EMAIL'];
				else
					$arUser['EMAIL'] = COption::GetOptionString('main', 'email_from', 'admin@'.$_SERVER['SERVER_NAME']);

				if ($arUser['EMAIL'] && $this->arParams['UNIQUE_EMAIL'] != 'N')
					$arUser['EMAIL'] = preg_replace('/@/', '_'.$USER_COUNTER.'@', $arUser['EMAIL'], 1);
			}

			$arUser['LID'] = $this->arParams['SITE_ID'];

			// set user groups list to default from main module setting
			if (is_array($this->arUserGroups)) 
				$arUser['GROUP_ID'] = $this->arUserGroups;

			// create new user
			if ($CURRENT_USER = $obUser->Add($arUser))
			{
				$counter['ADD']++;

				if ($this->arParams['EMAIL_NOTIFY'] == 'Y' || $this->arParams['EMAIL_NOTIFY'] == 'E' && $bEmailExists)
				{
					$arUser['ID'] = $CURRENT_USER;
					
					$this->__user->SendUserInfo(
						$CURRENT_USER, 
						$this->arParams['SITE_ID'], 
						'', 
						$this->arParams['EMAIL_NOTIFY_IMMEDIATELY'] == 'Y'
					);
				}
			}
			else
			{
				$CURRENT_USER = $obUser->LAST_ERROR;
				$GLOBALS['APPLICATION']->ThrowException($CURRENT_USER);
			}

			if (!$res = ($CURRENT_USER > 0))
				$USER_COUNTER--;
		}
	
		if (!$res)
		{
			$counter['ERR']++;
//			$fp = fopen($_SERVER['DOCUMENT_ROOT'].'/user.log', 'a');
//			fwrite($fp, "==============================================================\r\n");
//			fwrite($fp, $obUser->LAST_ERROR."\r\n");
//			fwrite($fp, print_r($arUser, true));
//			fwrite($fp, "==============================================================\r\n");
//			fclose($fp);
		}

		return $CURRENT_USER;
	}

	private function GetPersonGUID($codes)
	{
		foreach ($codes as $code)
		{
			$attr = $code->attributes();
			$attr = (array)$attr;
			$attr = array_shift($attr);
			if ($attr['schemeID'] == 'GUID' && $attr['schemeName'] == $this->PersonIDSchemeName)
				return (string)$code;
		}
	}

	private function GetGUID($codes)
	{
		foreach ($codes as $code)
		{
			$attr = $code->attributes();
			$attr = (array)$attr;
			$attr = array_shift($attr);
			if ($attr['schemeID'] == 'GUID')
				return (string)$code;
		}
	}

	private function FindUserByPersonID($personGUID)
	{
		if (array_key_exists($personGUID, $this->_users))
			return $personGUID;
		return false;
	}

	private function GetCurrentName($person)
	{
		$arResult = array();
		$last = '';
		foreach ($person->PersonName as $name)
		{
			$attr = $name->attributes();
			$attr = (array) $attr;
			$attr = array_shift($attr);
			if (empty($attr))
				continue;
			if (!array_key_exists('validFrom', $attr))
				continue;
			if ($attr['validFrom'] > $last){
				$arResult = $name;
				$last = $attr['validFrom'];
			}
		}
		return $arResult;
	}

	private function GetStructureRoot()
	{
		$departmentRepository = \Bitrix\Intranet\Service\ServiceContainer::getInstance()
			->departmentRepository();
		$department = $departmentRepository->getRootDepartment();
		if ($department)
		{
			return (string)$department->getId();
		}

		$company_name = COption::GetOptionString("main", "site_name", "");
		if ($company_name == '')
		{
			$dbrs = CSite::GetList('', '', array("DEFAULT" => "Y"));
			if ($ars = $dbrs->Fetch())
				$company_name = $ars["NAME"];
		}

		try
		{
			$department = $departmentRepository->save(new \Bitrix\Intranet\Entity\Department(
				$company_name
			));

			return (string)$department->getId();
		}
		catch (\Exception)
		{
			return false;
		}
	}
	private function LoadUserCodes()
	{
		$arResult = array();
		global $DB;
		$tblName = 'b_user';
		if ($DB->TableExists($tblName))
		{
			$rs = $DB->Query(
				"SELECT ID, XML_ID, ACTIVE FROM ".$tblName." WHERE XML_ID IS NOT NULL AND XML_ID <> ''"
			);
			while ($ar = $rs->Fetch())
			{
				$arResult[$ar['XML_ID']] = array(
					'ID' => $ar['ID'],
					'PERSON_ID' => $ar['XML_ID'],
				);
			}
		}

		return $arResult;
	}
}

function hr_SortIDArray($first, $second)
{
	$first = mb_strlen($first);
	$second = mb_strlen($second);
	if ($first > $second)
		return 1;
	if ($first < $second)
		return -1;
	return 0;
}

if (!class_exists('CArray2XML'))
{
	class CArray2XML
	{
		private $version;
		private $encoding;
		private $rootName;
		private $xml;
		private $elementStack = array();

		function __construct($root = 'root', $version = '1.0', $encoding = 'UTF-8')
		{
			$this->version = $version;
			$this->encoding = $encoding;
			$this->rootName = $root;
		}

		public function SaveToFile($arData, $fileDir, $fileName)
		{
			$xmlStr = $this->Convert($arData);
			if (!$handle=fopen($fileDir.$fileName.'.xml', 'w'))
				return false;
			if (fwrite($handle, $xmlStr)===false)
				return false;
			fclose($handle);
			return $fileDir.$fileName.'.xml';
		}

		public function Convert($arData, $startDocument = true)
		{
			if ($startDocument)
				$this->StartDocument();
			$this->StartElement($this->rootName);
			if (is_array($arData))
				$this->getXML($arData);
			$this->EndElement();
			return $this->xml;
		}

		private function StartDocument()
		{
			$this->xml = '<?xml version="'.$this->version.'" encoding="'.$this->encoding.'"?>';
		}

		private function StartElement($key, $value='')
		{
			if ($st = mb_strpos($key, '>>'))
			{
				$data = ' '.mb_substr($key, $st + 2);
				$key = mb_substr($key, 0, $st);
			}
			else $data = '';
			array_push($this->elementStack, $key);
			$cnt = count($this->elementStack)-1;
			$this->xml .= "\n".str_repeat("\t", $cnt).'<'.$key.$data.'>';
			$this->xml .= $value;
		}

		private function EndElement($nl=true)
		{
			$cnt = count($this->elementStack)-1;
			$this->xml .= $nl ? "\n".str_repeat("\t", $cnt) : '';
			$this->xml .= '</'.array_pop($this->elementStack).'>';
		}

		private function getXML($data, $prevKey = '')
		{
			foreach ($data as $key => $val)
			{
				if (is_numeric($key))
					$key = $prevKey;
				if (is_array($val))
				{
					$keys = array_keys($val);
					if (is_numeric($keys[0]))
					{
						$this->getXML($val, $key);
					}
					else
					{
						$this->StartElement($key);
						$this->getXML($val, $key);
						$this->EndElement();
					}
				}
				else
				{
					$this->StartElement($key, $val);
					$this->EndElement(false);
				}
			}
		}
	}
}

?>