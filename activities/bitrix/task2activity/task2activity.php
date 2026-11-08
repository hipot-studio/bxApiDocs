<?php

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Tasks\Integration\BizProc\NodeFilter\TargetDocumentResolverTrait;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Integration\AiAssistant\ActivityAiPropertyConverter;
use Bitrix\Bizproc\Result\ResultDto;

use Bitrix\Crm\Activity\Provider\Tasks\Task;
use Bitrix\Crm\Integration\Analytics\Dictionary;

use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Web\Uri;

use Bitrix\Tasks;
use Bitrix\Tasks\Flow\Provider\FlowProvider;
use Bitrix\Tasks\Flow\Provider\Query\ExpandedFlowQuery;
use Bitrix\Tasks\V2\Internal\Entity\Analytics;
use Bitrix\Tasks\V2\Internal\Entity\Analytics\AnalyticsData;

\Bitrix\Main\Loader::includeModule('tasks');

class CBPTask2Activity extends CBPActivity implements
	IBPEventActivity,
	IBPActivityExternalEventListener,
	IBPEventDrivenActivity
{
	use TargetDocumentResolverTrait;

	private $isInEventActivityMode = false;
	private static $cycleCounter = [];
	const CYCLE_LIMIT = 3;
	const CHECK_LIST_ITEMS_MAX_COUNT = 50;
	const CHECK_LIST_ITEM_MAX_LENGTH = 500;
	const CHECK_LISTS_MAX_COUNT = 10;

	/**
	 * Opens a check list inside the flat value of the property: the element carrying it is a title,
	 * every element after it is an item of that check list. Language independent on purpose - the value
	 * of a template is read by portals of any language, and the user never sees the prefix itself.
	 */
	public const CHECK_LIST_TITLE_PREFIX = '[CHECKLIST]';

	/** An item whose own text starts with the prefix carries this in front of it. */
	private const CHECK_LIST_ESCAPE = '\\';

	/** The channel of row kinds the properties dialog prints next to the values of the field (TPL-01). */
	public const CHECK_LIST_KINDS_FIELD = 'CHECK_LIST_ITEM_KINDS';
	public const CHECK_LIST_TITLE_KIND = 'title';
	public const CHECK_LIST_ITEM_KIND = 'item';

	private const SECURE_FILE_URL_SCHEMES = ['http', 'https', 'ftp', 'ftps'];

	private static array $arAllowedTasksFieldNames = [
		'TITLE',
		'CREATED_BY',
		'RESPONSIBLE_ID',
		'ACCOMPLICES',
		'START_DATE_PLAN',
		'END_DATE_PLAN',
		'DEADLINE',
		'DESCRIPTION',
		'PRIORITY',
		'GROUP_ID',
		'FLOW_ID',
		'ALLOW_CHANGE_DEADLINE',
		'TASK_CONTROL',
		'ADD_IN_REPORT',
		'AUDITORS',
		'ALLOW_TIME_TRACKING',
		'PARENT_ID',
		'TAG_NAMES',
		'REQUIRED_RESULT',
		'DEPENDS_ON',
	];

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = array(
			"Title"                   => "",
			"Fields"                  => null,
			"HoldToClose"             => false,
			"AUTO_LINK_TO_CRM_ENTITY" => true,
			"AsChildTask"             => 0,
			"CheckListItems"          => null,
			"TimeEstimateHour"          => null,
			"TimeEstimateMin"          => null,

			//return properties
			"ClosedBy"                => null,
			"ClosedDate"              => null,
			"TaskId"                  => null,
			"IsDeleted"               => 'N',
		);

		$this->SetPropertiesTypes([
			'TaskId' => ['Type' => 'int'],
			'ClosedBy' => ['Type' => 'user'],
			'ClosedDate' => ['Type' => 'datetime'],
			'IsDeleted' => ['Type' => 'bool'],
		]);
	}

	protected function ReInitialize()
	{
		parent::ReInitialize();

		$this->ClosedBy = null;
		$this->ClosedDate = null;
		$this->TaskId = null;
		$this->IsDeleted = 'N';
	}

	public function Cancel()
	{
		if (!$this->isInEventActivityMode && $this->HoldToClose)
		{
			$this->Unsubscribe($this);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	public function Execute()
	{
		$documentId = $this->resolveTargetDocumentId();
		$documentType = $this->resolveTargetDocumentType($documentId);
		$moduleId = $documentId[0] ?? null;

		$this->HoldToClose = CBPHelper::getBool($this->HoldToClose);
		$this->AUTO_LINK_TO_CRM_ENTITY = CBPHelper::getBool($this->AUTO_LINK_TO_CRM_ENTITY);
		$this->AsChildTask = CBPHelper::getBool($this->AsChildTask);
		$this->REQUIRED_RESULT = CBPHelper::getBool($this->REQUIRED_RESULT);

		if ($this->isInEventActivityMode)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (!$this->createTask())
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (
			Loader::includeModule('crm')
			&& method_exists(CCrmBizProcHelper::class, 'sendOperationsAnalytics')
		)
		{
			\CCrmBizProcHelper::sendOperationsAnalytics(
				Dictionary::EVENT_ENTITY_CREATE,
				$this,
				$documentType[2] ?? '',
				$moduleId,
			);
		}

		if (!$this->HoldToClose)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->Subscribe($this);
		$this->isInEventActivityMode = false;

		return CBPActivityExecutionStatus::Executing;
	}

	private function createTask()
	{
		if (!CModule::IncludeModule("tasks"))
		{
			return false;
		}

		$documentId = $this->resolveTargetDocumentId();
		$documentType = $this->resolveTargetDocumentType($documentId);

		$logMap = static::getPropertiesMap($this->getDocumentType());

		$this->checkCycling($documentId);

		$fields = $this->Fields;

		$fields["CREATED_BY"] = CBPHelper::ExtractUsers($this->Fields["CREATED_BY"], $documentId, true);
		$fields["RESPONSIBLE_ID"] = CBPHelper::ExtractUsers($this->Fields["RESPONSIBLE_ID"], $documentId, true);
		$fields["ACCOMPLICES"] = CBPHelper::ExtractUsers($this->Fields["ACCOMPLICES"], $documentId);
		$fields["AUDITORS"] = CBPHelper::ExtractUsers($this->Fields["AUDITORS"], $documentId);

		if (!isset($fields['SITE_ID']) || !$fields["SITE_ID"])
		{
			$fields["SITE_ID"] = SITE_ID;
		}

		if (!is_array($fields['UF_CRM_TASK'] ?? null))
		{
			$fields['UF_CRM_TASK'] = isset($fields['UF_CRM_TASK']) ? [$fields['UF_CRM_TASK']] : [];
		}
		if ($this->AUTO_LINK_TO_CRM_ENTITY && $documentId[0] === 'crm' && CModule::IncludeModule('crm'))
		{
			$letter = CCrmOwnerTypeAbbr::ResolveByTypeID(CCrmOwnerType::ResolveID($documentType[2]));

			$fields['UF_CRM_TASK'][] = str_replace($documentType[2], $letter, $documentId[2]);
		}

		if ($documentId[0] === 'tasks' && $this->AsChildTask)
		{
			$fields['PARENT_ID'] = $documentId[2];

			if (empty($fields['GROUP_ID']))
			{
				$res = \CTasks::GetList(
					[], ['ID' => (int)$fields['PARENT_ID'], 'CHECK_PERMISSIONS' => 'N'], ['GROUP_ID']
				);
				if ($res && ($task = $res->fetch()))
				{
					$fields['GROUP_ID'] = $task['GROUP_ID'];
				}
			}
		}
		elseif (!$this->AsChildTask && CBPHelper::isEmptyValue($fields['PARENT_ID']) === false)
		{
			$parentId = is_array($fields['PARENT_ID']) ? reset($fields['PARENT_ID']) : $fields['PARENT_ID'];
			if (
				!is_numeric($parentId)
				|| is_null(\Bitrix\Tasks\Integration\Bizproc\Document\Task::getDocument($parentId))
			)
			{
				$parentIdModifiedProperty = $logMap['PARENT_ID'];
				$parentIdModifiedProperty['BaseType'] = 'string';
				if ($this->workflow->isDebug())
				{
					$this->writeDebugInfo(
						$this->getDebugInfo(['PARENT_ID' => $parentId], ['PARENT_ID' => $parentIdModifiedProperty])
					);
				}

				$this->WriteToTrackingService(
					Loc::getMessage('BPTA1A_TASK_TASK_PRESENCE_ERROR', ['#TASK_ID#' => $parentId]),
					0,
					CBPTrackingType::Error
				);

				return false;
			}
			elseif (empty($fields['GROUP_ID']))
			{
				$res = \CTasks::GetList(
					[], ['ID' => (int)$parentId, 'CHECK_PERMISSIONS' => 'N'], ['GROUP_ID']
				);
				if ($res && ($task = $res->fetch()))
				{
					$fields['GROUP_ID'] = $task['GROUP_ID'];
				}
			}
			$fields['PARENT_ID'] = (int)$parentId;
		}

		$arUnsetFields = [];
		foreach ($fields as $fieldName => $fieldValue)
		{
			if (mb_substr($fieldName, -5) === '_text')
			{
				$fields[mb_substr($fieldName, 0, -5)] = $fieldValue;
				$arUnsetFields[] = $fieldName;
			}
		}

		foreach ($arUnsetFields as $fieldName)
		{
			unset($fields[$fieldName]);
		}

		// Check fields for "white" list
		$arFieldsChecked = [];
		$rawFields = $this->getRawProperty('Fields');
		foreach (array_keys($fields) as $fieldName)
		{
			if (
				in_array($fieldName, static::$arAllowedTasksFieldNames, true)
				|| \Bitrix\Tasks\Util\Userfield::isUFKey($fieldName)
			)
			{
				if ($fieldName === 'UF_TASK_WEBDAV_FILES' && Loader::includeModule('disk'))
				{
					$canUseDisk = \Bitrix\Disk\Configuration::isSuccessfullyConverted();

					$templateUserId = (int)$this->getTemplateUserId();

					if ($canUseDisk && $this->canUploadFilesToDisk($rawFields[$fieldName]))
					{
						$fields[$fieldName] = $this->uploadFilesToDisk($fields[$fieldName], $templateUserId);
					}
					elseif (is_array($fields[$fieldName]))
					{
						$storage = Driver::getInstance()->getStorageByUserId($templateUserId);

						if ($storage === null)
						{
							$fields[$fieldName] = [];
						}
						else
						{
							$securityContext = $storage->getSecurityContext($templateUserId);

							$filesToUpload = [];
							foreach ($fields[$fieldName] as $key => $fileId)
							{
								if ($canUseDisk && !empty($fileId))
								{
									$diskFile = File::load(['STORAGE_ID' => $storage->getId(), 'XML_ID' => (string)$fileId]);

									if ($diskFile !== null && !$diskFile->canRead($securityContext))
									{
										continue;
									}

									if ((!is_string($fileId) || mb_substr($fileId, 0, 1) !== 'n') && is_null($diskFile))
									{
										$filesToUpload[] = $fileId;
										unset($fields[$fieldName][$key]);
									}
									elseif (is_string($fileId) && mb_substr($fileId, 0, 1) !== 'n')
									{
										$fields[$fieldName][$key] = 'n' . $diskFile->getId();
									}
								}
							}

							$fields[$fieldName] = array_merge(
								$fields[$fieldName],
								$this->uploadFilesToDisk($filesToUpload, $templateUserId),
							);
						}
					}
				}

				$arFieldsChecked[$fieldName] = $fields[$fieldName];
			}
		}

		if (isset($arFieldsChecked['TAG_NAMES']))
		{
			if (is_array($arFieldsChecked['TAG_NAMES']))
			{
				$arFieldsChecked['TAGS'] = [];
				foreach ($arFieldsChecked['TAG_NAMES'] as $tagName)
				{
					if (is_numeric($tagName))
					{
						$arFieldsChecked['TAGS'][] = (string)$tagName;
					}
					elseif (is_string($tagName))
					{
						$arFieldsChecked['TAGS'][] = $tagName;
					}
				}
			}

			unset($arFieldsChecked['TAG_NAMES']);
		}

		if (empty($arFieldsChecked['CREATED_BY']))
		{
			if ($this->workflow->isDebug())
			{
				$this->writeDebugInfo(
					$this->getDebugInfo(
						['CREATED_BY' => $arFieldsChecked['CREATED_BY']], ['CREATED_BY' => $logMap['CREATED_BY']]
					)
				);
			}

			$this->WriteToTrackingService(
				Loc::getMessage("BPSA_CREATED_BY_ERROR"),
				0,
				CBPTrackingType::Error
			);

			return false;
		}

		if (isset($arFieldsChecked['GROUP_ID']))
		{
			$arFieldsChecked['GROUP_ID'] = (int)CBPHelper::stringify($arFieldsChecked['GROUP_ID']);
		}

		if (!CBPHelper::isEmptyValue($rawFields['FLOW_ID']))
		{
			$arFieldsChecked['FLOW_ID'] = (int)CBPHelper::stringify($arFieldsChecked['FLOW_ID']);
			if ($arFieldsChecked['FLOW_ID'] <= 0)
			{
				$this->WriteToTrackingService(
					Loc::getMessage('BPTA1A_TASK_FLOW_PRESENCE_ERROR'),
					0,
					CBPTrackingType::Error
				);

				return false;
			}
		}

		$allDateFields = array_merge(
			['DEADLINE', 'END_DATE_PLAN', 'START_DATE_PLAN'],
			\Bitrix\Tasks\Integration\Bizproc\Document\Task::getFieldsCreatedByUser('datetime')
		);
		foreach ($allDateFields as $dateField)
		{
			$dateFieldName = is_array($dateField) ? $dateField['Name'] : $dateField;
			$checkedDateField = $this->assertDateField($dateField, $arFieldsChecked[$dateFieldName] ?? null);

			// In some cases (crm), we got time in user timezone
			if ($dateFieldName === 'DEADLINE')
			{
				$deadlineValue = $arFieldsChecked[$dateFieldName] ?? null;
				if ($deadlineValue instanceof \Bitrix\Bizproc\BaseType\Value\DateTime)
				{
					$checkedDateField = date($deadlineValue->getFormat(), $deadlineValue->getTimestamp());
				}
			}

			if (!$checkedDateField)
			{
				unset($arFieldsChecked[$dateFieldName]);
			}
			else
			{
				$arFieldsChecked[$dateFieldName] = $checkedDateField;
			}
		}

		$this->checkPlanDates($arFieldsChecked);
		$this->checkSeParameter($arFieldsChecked);

		if ($fields['ALLOW_TIME_TRACKING'] === 'Y')
		{
			$arFieldsChecked['TIME_ESTIMATE'] = (int)$this->TimeEstimateHour * 3600 + (int)$this->TimeEstimateMin * 60;
		}

		$prevOccurAsUserId = \Bitrix\Tasks\Util\User::getOccurAsId(); // null or positive integer
		\Bitrix\Tasks\Util\User::setOccurAsId($arFieldsChecked['CREATED_BY']);

		$result = false;
		$task = null;
		$errors = [];
		try
		{
			if ($this->workflow->isDebug())
			{
				$this->writeDebugInfo(
					$this->getDebugInfo(array_merge($arFieldsChecked, ['HoldToClose' => $this->HoldToClose]))
				);
			}

			// todo: change to tasks v2 add command
			$task = CTaskItem::add(
				$arFieldsChecked,
				(int)$arFieldsChecked['CREATED_BY'],
				[
					'SPAWNED_BY_WORKFLOW' => true,
					'SKIP_TIMEZONE' => [
						'DEADLINE',
					],
					'CHECK_ACCESS' => false,
					'ANALYTICS_DATA' => $this->getAnalyticsData($documentType),
				],
			);
			$result = $task->getId();
		}
		catch (TasksException $e)
		{
			// todo: incapsulate this
			if ($e->checkOfType(TasksException::TE_FLAG_SERIALIZED_ERRORS_IN_MESSAGE))
			{
				$errors = unserialize($e->getMessage(), ['allowed_classes' => false]);
			}
		}

		\Bitrix\Tasks\Util\User::setOccurAsId($prevOccurAsUserId);

		if (!$result)
		{
			if (count($errors) > 0)
			{
				$errorDesc = [];
				if (is_array($errors) && !empty($errors))
				{
					foreach ($errors as $error)
					{
						$errorDesc[] = $error['text'] . ' (' . $error['id'] . ')';
					}
				}

				$this->WriteToTrackingService(
					html_entity_decode(
						Loc::getMessage("BPSA_TRACK_ERROR") . (!empty($errorDesc) ? ' ' . implode(', ', $errorDesc)
							: '')
					),
					0,
					CBPTrackingType::Error
				);
			}

			return false;
		}

		// grouping is read from the template, before expressions are substituted: a value coming from
		// a field of the document must not be able to open a check list of its own
		$checkLists = static::parseCheckLists($this->getRawProperty('CheckListItems'));
		if ($checkLists)
		{
			$this->writeCheckListDebugInfo(
				$this->createCheckLists(
					CTaskItem::getInstance($result, $arFieldsChecked['CREATED_BY']),
					$checkLists,
				)
			);
		}

		$this->TaskId = $result;
		$this->markAsBPTask($result);
		$this->WriteToTrackingService(str_replace("#VAL#", $result, Loc::getMessage("BPSA_TRACK_OK")));

		if (
			$this->TaskId
			&& method_exists($this, 'fixResult')
		)
		{
			$this->fixResult($this->makeResultFromId($this->TaskId));
		}

		if ($this->workflow->isDebug())
		{
			$this->logDebugTaskUrl($result);
		}

		return $result > 0;
	}

	public function makeResultFromId(int $id): ResultDto
	{
		$resultDocumentId = Tasks\Integration\Bizproc\Document\Task::resolveDocumentId($id);
		$resultDocumentType = $resultDocumentId;
		array_pop($resultDocumentType);
		$resultDocumentType[] = 'TASK';
		$resultValue = [
			'DOCUMENT_ID' => $resultDocumentId,
			'DOCUMENT_TYPE' => $resultDocumentType,
		];

		return new ResultDto(get_class($this), $resultValue);
	}

	protected function canUploadFilesToDisk($value) : bool
	{
		return is_string($value) && CBPActivity::isExpression($value);
	}

	protected function uploadFilesToDisk($files, $userId) : array
	{
		if ($userId <= 0)
		{
			return [];
		}

		if (is_null($files))
		{
			return [];
		}

		if (!is_array($files))
		{
			$files = [$files];
		}

		$diskFilesIds = [];
		foreach ($files as $file)
		{
			$fileArray = $this->resolveFile($file);

			if ($fileArray === null)
			{
				continue;
			}

			$uploadedFile = \Bitrix\Tasks\Integration\Disk\UserField::uploadFile($fileArray, $userId);

			if ($uploadedFile->isSuccess())
			{
				$diskFilesIds[] = $uploadedFile->getData()['ATTACHMENT_ID'];
			}
		}

		return $diskFilesIds;
	}

	private function resolveFile(mixed $file): ?array
	{
		if (is_numeric($file))
		{
			$fileArray = CFile::MakeFileArray($file);
		}
		elseif (is_string($file))
		{
			$fileUrl = self::getSecureFileUrl($file);
			if ($fileUrl === null)
			{
				return null;
			}

			$fileArray = CFile::MakeFileArray($fileUrl);
		}
		else
		{
			return null;
		}

		// A failed download still returns an array, but without the file itself.
		return (is_array($fileArray) && !empty($fileArray['tmp_name'])) ? $fileArray : null;
	}

	private static function getSecureFileUrl(string $file): ?string
	{
		$file = trim($file);
		if ($file === '')
		{
			return null;
		}

		$uri = new Uri($file);
		$scheme = mb_strtolower($uri->getScheme());
		if ($scheme !== '' && !in_array($scheme, self::SECURE_FILE_URL_SCHEMES, true))
		{
			return null;
		}

		if ($uri->getHost() === '')
		{
			// The activity runs in agents too, so the portal host comes from the settings, not from the request.
			$uri = new Uri(UrlManager::getInstance()->getHostUrl() . '/' . ltrim($file, '/'));
		}

		if (
			$uri->getHost() === ''
			|| $uri->isPathTraversal()
			|| !in_array(mb_strtolower($uri->getScheme()), self::SECURE_FILE_URL_SCHEMES, true)
		)
		{
			return null;
		}

		return $uri->getUri();
	}

	protected function assertDateField($dateField, $dateFieldValue)
	{
		if(is_object($dateFieldValue))
		{
			return (string) $dateFieldValue;
		}
		elseif(is_array($dateFieldValue))
		{
			$realDateField = [];
			foreach ($dateFieldValue as $date)
			{
				$checkedField = $this->assertDateField($dateField, $date);
				if(is_array($checkedField))
				{
					$realDateField = array_merge($realDateField, $checkedField);
				}
				elseif($checkedField)
				{
					$realDateField[] = $checkedField;
				}
			}

			if (is_array($realDateField) && (is_string($dateField) || empty($dateField['Multiple'])))
			{
				return current($realDateField);
			}

			return $realDateField;
		}

		if (!empty($dateFieldValue) && !CheckDateTime($dateFieldValue))
		{
			$name = is_array($dateField) ? $dateField['Name'] : $dateField;
			$this->WriteToTrackingService(
				'Incorrect ' . $name . ': ' . $dateFieldValue,
				0,
				CBPTrackingType::Error
			);

			return false;
		}

		return $dateFieldValue;
	}

	protected function checkPlanDates(&$fields)
	{
		if (!empty($fields['START_DATE_PLAN']) && !empty($fields['END_DATE_PLAN']))
		{
			$startDate = MakeTimeStamp($fields['START_DATE_PLAN']);
			$endDate = MakeTimeStamp($fields['END_DATE_PLAN']);
			if ($endDate < $startDate)
			{
				unset($fields['END_DATE_PLAN']);
			}
		}
	}

	private function checkSeParameter(&$fields): void
	{
		$parameters = [];
		if (isset($fields['REQUIRED_RESULT']))
		{
			$parameters[\Bitrix\Tasks\Internals\Task\ParameterTable::PARAM_RESULT_REQUIRED] = [
				'VALUE' => CBPHelper::getBool($fields['REQUIRED_RESULT']) ? 'Y' : 'N',
				'CODE' => \Bitrix\Tasks\Internals\Task\ParameterTable::PARAM_RESULT_REQUIRED,
				'ID' => '0',
			];

			unset($fields['REQUIRED_RESULT']);
		}

		if ($parameters)
		{
			$fields['SE_PARAMETER'] = $parameters;
		}
	}

	private function getAnalyticsData(?array $documentType): AnalyticsData
	{
		$moduleId = $documentType[0] ?? null;

		$section = match ($moduleId)
		{
			'tasks' => Analytics\Section::Tasks,
			'crm' => Analytics\Section::Crm,
			default => Analytics\Section::BizProc,
		};

		return new AnalyticsData(
			category: Analytics\Category::TaskOperations,
			section: $section,
			subSection: Analytics\SubSection::Automation,
			element: Analytics\Element::Auto,
		);
	}

	protected function markAsBPTask(int $taskId): void
	{
		if(!CModule::IncludeModule('crm'))
		{
			return;
		}

		$splitDocumentId = mb_split('_(?=[^_]*$)', $this->GetDocumentId()[2]);

		if (!is_array($splitDocumentId) || count($splitDocumentId) < 2)
		{
			return;
		}

		[$documentType, $documentId] = $splitDocumentId;

		$documentStage = $this->getDocumentStage($documentType);

		if($documentStage !== '')
		{
			$activity = CCrmActivity::GetList(
				[],
				[
					'CHECK_PERMISSIONS' => 'N',
					'OWNER_ID' => $documentId,
					'OWNER_TYPE_ID' => CCrmOwnerType::ResolveID($documentType),
					'TYPE_ID' => CCrmActivityType::Task,
					'ASSOCIATED_ENTITY_ID' => $taskId,
				],
				false,
				false,
				['ID']
			)->Fetch();

			if($activity)
			{
				CCrmActivity::Update(
					$activity['ID'],
					['SETTINGS' => ['OWNER_STAGE' => $documentStage]],
					false,
					false
				);
			}
			else
			{
				$activity = CCrmActivity::GetList(
					[],
					[
						'CHECK_PERMISSIONS' => 'N',
						'OWNER_ID' => $documentId,
						'OWNER_TYPE_ID' => CCrmOwnerType::ResolveID($documentType),
						'TYPE_ID' => CCrmActivityType::Provider,
						'PROVIDER_ID' => Task::getId(),
						'PROVIDER_TYPE_ID' => Task::getProviderTypeId(),
						'ASSOCIATED_ENTITY_ID' => $taskId,
					],
					false,
					false,
					['ID', 'SETTINGS']
				)->Fetch();
				if ($activity)
				{
					$settings = array_merge(['OWNER_STAGE' => $documentStage], $activity['SETTINGS']);
					CCrmActivity::Update(
						$activity['ID'],
						['SETTINGS' => $settings],
						false,
						false
					);
				}
			}
		}
	}

	protected function getDocumentStage(string $documentType): string
	{
		if(!CModule::IncludeModule('crm'))
		{
			return '';
		}

		$runtime = CBPRuntime::GetRuntime();
		$runtime->StartRuntime();
		$documentService = $runtime->GetService('DocumentService');

		if (defined('\CBPDocument::PARAM_USED_DOCUMENT_FIELDS'))
		{
			$document = $documentService->GetDocument($this->GetDocumentId(), select: ['STATUS_ID', 'STAGE_ID']);
		}
		else
		{
			$document = $documentService->GetDocument($this->GetDocumentId());
		}

		switch ($documentType)
		{
			case CCrmOwnerType::LeadName:
			case CCrmOwnerType::QuoteName:
			case CCrmOwnerType::OrderName:
				return $document['STATUS_ID'] ?? '';

			case CCrmOwnerType::DealName:
			case CCrmOwnerType::SmartDocumentName:
			case CCrmOwnerType::SmartInvoiceName:
				return $document['STAGE_ID'] ?? '';

			default:
				$documentTypeId = CCrmOwnerType::ResolveID($documentType);
				if(
					method_exists(CCrmOwnerType::class, 'isPossibleDynamicTypeId')
					&& CCrmOwnerType::isPossibleDynamicTypeId($documentTypeId)
				)
				{
					return $document['STAGE_ID'] ?? $document['STATUS_ID'] ?? '';
				}
				return '';
		}
	}

	public function Subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$this->isInEventActivityMode = true;

		if ($eventHandler instanceof CBPListenEventActivitySubscriber)
		{
			$result = $this->createTask();
			if (!$result)
			{
				return false;
			}
		}

		$taskId = (int)$this->TaskId;
		if (!$taskId)
		{
			return false;
		}

		$schedulerService = $this->workflow->GetService("SchedulerService");
		$schedulerService->SubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskUpdate", $taskId);
		$schedulerService->SubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskDelete", $taskId);

		$this->workflow->AddEventHandler($this->name, $eventHandler);
		$this->WriteToTrackingService(Loc::getMessage("BPSA_TRACK_SUBSCR"));
	}

	public function Unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$taskId = (int)$this->TaskId;

		$schedulerService = $this->workflow->GetService("SchedulerService");
		$schedulerService->UnSubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskUpdate", $taskId);
		$schedulerService->UnSubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskDelete", $taskId);

		//delete invalid subscriptions
		$schedulerService->UnSubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskUpdate", null);
		$schedulerService->UnSubscribeOnEvent($this->workflow->GetInstanceId(), $this->name, "tasks", "OnTaskDelete", null);

		$this->workflow->RemoveEventHandler($this->name, $eventHandler);
	}

	public function OnExternalEvent($arEventParameters = array())
	{
		if ($this->onExternalEventHandler($arEventParameters))
		{
			$this->Unsubscribe($this);
			$this->workflow->CloseActivity($this);
		}

		return;
	}

	public function OnExternalDrivenEvent($arEventParameters = array())
	{
		return $this->onExternalEventHandler($arEventParameters);
	}

	private function onExternalEventHandler($arEventParameters = array())
	{
		if ($this->TaskId != $arEventParameters[0])
		{
			return;
		}

		$this->resolveTargetDocumentId();

		if ($this->executionStatus != CBPActivityExecutionStatus::Closed)
		{
			if (isset($arEventParameters['eventName']) && $arEventParameters['eventName'] === 'OnTaskDelete')
			{
				$this->IsDeleted = 'Y';
				$this->WriteToTrackingService(Loc::getMessage("BPSA_TRACK_DELETED"));
				return true;
			}
			elseif ($arEventParameters[1]["STATUS"] == 5)
			{
				$this->ClosedBy = "user_".$arEventParameters[1]["CLOSED_BY"];
				$this->ClosedDate = $arEventParameters[1]["CLOSED_DATE"];

				$this->WriteToTrackingService(str_replace("#DATE#", $arEventParameters[1]["CLOSED_DATE"], Loc::getMessage("BPSA_TRACK_CLOSED")));

				return !$this->isAutoCompleted($arEventParameters);
			}
		}
	}

	private function isAutoCompleted(array $params): bool
	{
		if ($this->getRootActivity()->getDocumentEventType() !== \CBPDocumentEventType::Automation)
		{
			return false; //skip none-automation
		}

		if(!CModule::IncludeModule('crm'))
		{
			return false;
		}

		[$typeId, $id] = \CCrmBizProcHelper::resolveEntityId($this->getDocumentId());

		if ($typeId)
		{
			$activity = CCrmActivity::GetList(
				[],
				[
					'OWNER_ID' => $id,
					'OWNER_TYPE_ID' => $typeId,
					'@PROVIDER_ID' => [Task::getId(), \Bitrix\Crm\Activity\Provider\Tasks\Task::getProviderTypeId()],
					'ASSOCIATED_ENTITY_ID' => $params[0],
					'STATUS' => CCrmActivityStatus::AutoCompleted,
				],
				false,
				false,
				['ID']
			)->fetch();

			if ($activity)
			{
				return true;
			}
		}

		return false;
	}

	public static function getPropertiesDialog($documentType, $activityName, $arWorkflowTemplate, $arWorkflowParameters, $arWorkflowVariables, $arCurrentValues = null, $formName = "", $popupWindow = null, $currentSiteId = null)
	{
		if (!\Bitrix\Main\Loader::includeModule('tasks'))
		{
			return false;
		}

		$dialog = new PropertiesDialog(__FILE__, array(
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $currentSiteId,
		));

		$map = static::getPropertiesDialogMap();
		foreach ($map['Fields']['Map'] as &$field)
		{
			if ($field['Type'] === FieldType::USER && $field['Required'])
			{
				$field['Default'] = \Bitrix\Bizproc\Automation\Helper::getResponsibleUserExpression($documentType);
			}
		}

		unset($field);
		$dialog->setMap($map);

		$currentValues = $dialog->getCurrentValues();

		// compatibility
		foreach ($map as $field)
		{
			$fieldName = $field['FieldName'];
			if (isset($currentValues[$fieldName], $field['Type']) && $field['Type'] === FieldType::BOOL)
			{
				$currentValues[$fieldName] = CBPHelper::getBool($currentValues[$fieldName]) ? 'Y' : 'N';
			}
		}

		$dialog->setCurrentValues($currentValues);

		$runtimeData = [
			'tags' => [],
			'dependsOn' => [],
		];
		if (isset($currentValues['Fields']))
		{
			if (isset($currentValues['Fields']['TAG_NAMES']) && $currentValues['Fields']['TAG_NAMES'])
			{
				$runtimeData['tags'] = static::getTagsByNames($currentValues['Fields']['TAG_NAMES']);
			}
			if (isset($currentValues['Fields']['DEPENDS_ON']) && $currentValues['Fields']['DEPENDS_ON'])
			{
				$runtimeData['dependsOn'] = static::getDependsOnByTaskIds($currentValues['Fields']['DEPENDS_ON']);
			}
		}

		$dialog->setRuntimeData($runtimeData);

		return $dialog;
	}

	private static function getTagsByNames(array $tagNames): array
	{
		if (!$tagNames)
		{
			return [];
		}

		$tagsIterator = \Bitrix\Tasks\Internals\Task\LabelTable::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['@NAME' => $tagNames],
		]);

		$knownTags = [];
		while ($tag = $tagsIterator->fetchObject())
		{
			$knownTags[$tag->getName()] = $tag;
		}

		$tags = [];
		foreach ($tagNames as $name)
		{
			if (CBPDocument::isExpression($name))
			{
				$tags[] = [
					'type' => 'expression',
					'name' => $name,
				];
			}
			elseif (isset($knownTags[$name]))
			{
				$tags[] = [
					'type' => 'simple',
					'id' => $knownTags[$name]->getId(),
					'name' => $name,
				];
			}
			else
			{
				$tags[] = [
					'type' => 'new',
					'name' => $name,
				];
			}
		}

		return $tags;
	}

	public static function getDependsOnByTaskIds(array $taskIds): array
	{
		$dependsOn = [];

		foreach ($taskIds as $id)
		{
			$dependsOn[] = CBPDocument::isExpression($id) ? $id : (int)$id;
		}

		return $dependsOn;
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors
	)
	{
		if (!\Bitrix\Main\Loader::includeModule('tasks'))
		{
			return false;
		}

		$errors = [];
		$properties = ['Fields' => []];

		$documentService = CBPRuntime::getRuntime()->getDocumentService();

		if (isset($currentValues['FLOW_ID'])
			&& !CBPHelper::isEmptyValue($currentValues['FLOW_ID'])
		)
		{
			foreach ((new \Bitrix\Tasks\Flow\Control\Task\Field\FlowFieldHandler(0))->getModifiedFields() as $field)
			{
				$currentValues[$field] = null;
			}
		}

		// must run before extraction: it is the raw value of the form the check lists are composed from
		$checkListFormErrors = static::composeCheckListItemsValue($currentValues);

		$map = static::getPropertiesDialogMap();
		foreach ($map['Fields']['Map'] as $taskFieldId => $taskField)
		{
			$field = $documentService->getFieldTypeObject($documentType, $taskField);
			if (!$field)
			{
				if (mb_substr($taskFieldId, 0, 3) === 'UF_')
				{
					$extractResult = static::extractUserField($taskField, $currentValues);
					if ($extractResult->isSuccess())
					{
						$properties['Fields'][$taskFieldId] = $extractResult->getData()['value'];
					}
					else
					{
						foreach ($extractResult->getErrors() as $extractError)
						{
							$errors[] = [
								'code' => $extractError->getCode(),
								'message' => $extractError->getMessage(),
								'parameter' => $taskField,
							];
						}
					}
				}
				continue;
			}

			$properties['Fields'][$taskFieldId] = $field->extractValue(
				['Field' => $taskField['FieldName']],
				$currentValues,
				$errors,
			);
		}

		$simpleMap = $map;
		unset($simpleMap['Fields']);
		foreach ($simpleMap as $fieldId => $fieldProperty)
		{
			$field = $documentService->getFieldTypeObject($documentType, $fieldProperty);
			if (!$field)
			{
				continue;
			}

			$properties[$fieldId] = $field->extractValue(
				['Field' => $fieldProperty['FieldName']],
				$currentValues,
				$errors,
			);
		}

		// must run after both extraction loops: every extractValue() call overwrites the errors array
		$errors = array_merge(
			$errors,
			$checkListFormErrors ?: static::collectCheckListItemsErrors($properties['CheckListItems'] ?? null),
		);

		if (!$errors)
		{
			$errors = array_merge($errors, static::validateProperties($properties));
		}
		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	/**
	 * The value of the property assembled from the two channels of the form (TPL-01). The kinds channel
	 * is the mark of authorship as well: a request without it was written past the dialog - by the robot
	 * of the automation among others - and its value passes through untouched, prefixes and all.
	 *
	 * @return array the errors of the form, empty when there are none
	 */
	private static function composeCheckListItemsValue(array &$currentValues): array
	{
		if (!isset($currentValues[static::CHECK_LIST_KINDS_FIELD]))
		{
			return [];
		}

		$kinds = static::readCheckListChannel($currentValues[static::CHECK_LIST_KINDS_FIELD]);
		$texts = static::readCheckListChannel($currentValues['CHECK_LIST_ITEMS'] ?? []);

		// a default here would hide an outdated form, a partial delivery and a forged request alike,
		// and every one of them would end up merging check lists
		$isMalformed = count($kinds) !== count($texts) || array_filter($texts, 'is_array');

		if ($isMalformed)
		{
			return [[
				'code' => 'CheckListFormMalformed',
				'parameter' => 'CHECK_LIST_ITEMS',
				'message' => Loc::getMessage('BPSA_CHECK_LIST_FORM_ERROR'),
			]];
		}

		$currentValues['CHECK_LIST_ITEMS'] = static::composeCheckListValues(
			array_map(static fn ($text): string => (string)$text, $texts),
			array_map(static fn ($kind): string => is_array($kind) ? '' : (string)$kind, $kinds),
		);

		return [];
	}

	private static function readCheckListChannel($channel): array
	{
		return array_values(is_array($channel) ? $channel : [$channel]);
	}

	private static function collectCheckListItemsErrors($items): array
	{
		if (!is_array($items))
		{
			return [];
		}

		$errors = [];
		$lists = static::parseCheckLists($items);

		if (count($lists) > static::CHECK_LISTS_MAX_COUNT)
		{
			$errors[] = [
				'code' => 'CheckListsCountExceeded',
				'parameter' => 'CHECK_LIST_ITEMS',
				'message' => Loc::getMessage(
					'BPSA_CHECK_LISTS_COUNT_ERROR',
					['#LIMIT#' => static::CHECK_LISTS_MAX_COUNT]
				),
			];
		}

		foreach ($lists as $list)
		{
			if (count($list['items']) > static::CHECK_LIST_ITEMS_MAX_COUNT)
			{
				$errors[] = [
					'code' => 'CheckListItemsCountExceeded',
					'parameter' => 'CHECK_LIST_ITEMS',
					'message' => Loc::getMessage(
						'BPSA_CHECK_LIST_ITEMS_COUNT_ERROR',
						['#LIMIT#' => static::CHECK_LIST_ITEMS_MAX_COUNT]
					),
				];

				break;
			}
		}

		// the length is measured over the parsed text: the service prefix of a title and the escape of
		// an item are ours, and a title of exactly the limit must not fail over characters we added
		foreach ($lists as $list)
		{
			$lengthError = static::findCheckListLengthError($list);
			if ($lengthError !== null)
			{
				$errors[] = $lengthError;

				break;
			}
		}

		return $errors;
	}

	private static function findCheckListLengthError(array $list): ?array
	{
		foreach ($list['items'] as $item)
		{
			if (mb_strlen($item) > static::CHECK_LIST_ITEM_MAX_LENGTH)
			{
				return [
					'code' => 'CheckListItemLengthExceeded',
					'parameter' => 'CHECK_LIST_ITEMS',
					'message' => Loc::getMessage(
						'BPSA_CHECK_LIST_ITEM_LENGTH_ERROR',
						['#LIMIT#' => static::CHECK_LIST_ITEM_MAX_LENGTH]
					),
				];
			}
		}

		if (mb_strlen($list['title']) > static::CHECK_LIST_ITEM_MAX_LENGTH)
		{
			return [
				'code' => 'CheckListTitleLengthExceeded',
				'parameter' => 'CHECK_LIST_ITEMS',
				'message' => Loc::getMessage(
					'BPSA_CHECK_LIST_TITLE_LENGTH_ERROR',
					['#LIMIT#' => static::CHECK_LIST_ITEM_MAX_LENGTH]
				),
			];
		}

		return null;
	}

	/**
	 * The items of a stored check list, keyed by the position each one holds in the property.
	 * Empty items are dropped here exactly as extraction drops them on saving, so the properties
	 * dialog shows and execution creates the very items the limits are counted over.
	 *
	 * @return array<int, string>
	 */
	public static function reduceCheckListItemsToStrings($items): array
	{
		if (!is_array($items))
		{
			$items = [$items];
		}

		$strings = [];
		$number = 0;
		foreach ($items as $item)
		{
			$number++;
			$string = static::reduceCheckListItemToString($item);
			if ($string !== '')
			{
				$strings[$number] = $string;
			}
		}

		return $strings;
	}

	// the only definition of the item text: saving must measure exactly what execution creates
	private static function reduceCheckListItemToString($item): string
	{
		if (is_array($item))
		{
			$item = implode(', ', \CBPHelper::MakeArrayFlat($item));
		}

		return trim((string)$item);
	}

	/**
	 * A root per parsed check list, its items as children of that very root (ALG-02).
	 *
	 * Expressions are evaluated here, after the grouping is settled: the title on its own, the items of
	 * a group as one list, so that an item written as an expression of several values keeps unfolding
	 * into several items instead of collapsing into a comma separated string. A template written past
	 * the properties dialog carries a check list of any size, so the limits the dialog refuses to save
	 * are applied here as well: the task keeps what fits.
	 *
	 * @param list<array{hasTitle: bool, title: string, items: list<string>}> $checkLists
	 * @return list<string> the items of every check list with the expressions substituted, in order
	 */
	private function createCheckLists(CTaskItem $taskItem, array $checkLists): array
	{
		$skippedListsCount = count($checkLists) - static::CHECK_LISTS_MAX_COUNT;
		$checkLists = array_slice($checkLists, 0, static::CHECK_LISTS_MAX_COUNT);

		$resolvedItems = [];
		$number = 0;
		foreach ($checkLists as $checkList)
		{
			$number++;

			$title = static::reduceCheckListItemToString($this->parseValue($checkList['title']));
			$items = static::reduceCheckListItemsToStrings(
				$this->parseValue(static::keepCheckListItemsApart($checkList['items']))
			);
			$skippedItemsCount = count($items) - static::CHECK_LIST_ITEMS_MAX_COUNT;
			$items = array_slice($items, 0, static::CHECK_LIST_ITEMS_MAX_COUNT, true);

			if (!$items)
			{
				continue;
			}

			if (mb_strlen($title) > static::CHECK_LIST_ITEM_MAX_LENGTH)
			{
				$this->WriteToTrackingService(
					Loc::getMessage('BPSA_CHECK_LIST_ADD_ERROR_MSGVER_1', ['#LIST_NUMBER#' => (string)$number]),
					0,
					CBPTrackingType::Error
				);
				continue;
			}

			$resolvedItems = array_merge($resolvedItems, array_values($items));

			$root = null;
			try
			{
				$root = \CTaskCheckListItem::add($taskItem, [
					'TITLE' => $title === ''
						? Loc::getMessage('BPSA_CHECK_LIST_TITLE', ['#NUMBER#' => (string)$number])
						: $title,
					'PARENT_ID' => 0,
					'SORT_INDEX' => $number - 1,
				]);
			}
			catch (\Throwable)
			{
				$this->WriteToTrackingService(
					Loc::getMessage('BPSA_CHECK_LIST_ADD_ERROR_MSGVER_1', ['#LIST_NUMBER#' => (string)$number]),
					0,
					CBPTrackingType::Error
				);
			}

			// the note about the items left out speaks of a check list that exists: a root that failed
			// has already been reported, and nothing was added to it
			if ($root !== null)
			{
				$this->createCheckListItems($taskItem, $root->getId(), $items, $number);

				if ($skippedItemsCount > 0)
				{
					$this->WriteToTrackingService(
						Loc::getMessage(
							'BPSA_CHECK_LIST_ITEMS_COUNT_LIMITED_MSGVER_1',
							[
								'#LIMIT#' => (string)static::CHECK_LIST_ITEMS_MAX_COUNT,
								'#COUNT#' => (string)$skippedItemsCount,
								'#LIST_NUMBER#' => (string)$number,
							]
						),
						0,
						CBPTrackingType::Custom
					);
				}
			}
		}

		if ($skippedListsCount > 0)
		{
			$this->WriteToTrackingService(
				Loc::getMessage(
					'BPSA_CHECK_LISTS_COUNT_LIMITED',
					[
						'#LIMIT#' => (string)static::CHECK_LISTS_MAX_COUNT,
						'#COUNT#' => (string)$skippedListsCount,
					]
				),
				0,
				CBPTrackingType::Custom
			);
		}

		return $resolvedItems;
	}

	/**
	 * An array of exactly two strings is read by the engine as a pair of an object and a field of it
	 * (`CBPActivity::getPropertyValueRecursive()`), so a check list of two items named after one would
	 * collapse into a single value of the document. An empty element past the end leaves the list a
	 * list of items; it carries no text and is dropped with the other empty ones.
	 *
	 * @param list<string> $items
	 * @return list<string>
	 */
	private static function keepCheckListItemsApart(array $items): array
	{
		return count($items) === 2 ? [...$items, ''] : $items;
	}

	/**
	 * The items of the check lists are what the debug panel shows for the field, never the value of the
	 * property: the value carries the service prefix of the format and the titles of the check lists,
	 * and a title belongs to no message of the log (ADR, AC-026).
	 *
	 * @param list<string> $items
	 */
	private function writeCheckListDebugInfo(array $items): void
	{
		if (!$items || !$this->workflow->isDebug())
		{
			return;
		}

		$this->writeDebugInfo($this->getDebugInfo(
			['CHECK_LIST_ITEMS' => $items],
			[
				'CHECK_LIST_ITEMS' => [
					'Name' => Loc::getMessage('BPSA_CHECK_LIST_ITEMS'),
					'Type' => 'string',
					'Required' => false,
					'Multiple' => true,
				],
			]
		));
	}

	/**
	 * The position of an item inside its own check list is passed as the sort index: without it every
	 * single item makes the compatibility layer reread the whole check list tree of the task.
	 *
	 * Empty expressions leave gaps in sort indexes; tracking uses consecutive item numbers.
	 *
	 * @param array<int, string> $items
	 */
	private function createCheckListItems(CTaskItem $taskItem, int $rootId, array $items, int $listNumber): void
	{
		$number = 0;
		foreach ($items as $position => $text)
		{
			$number++;

			try
			{
				\CTaskCheckListItem::add($taskItem, [
					'TITLE' => $text,
					'PARENT_ID' => $rootId,
					'SORT_INDEX' => $position,
				]);
			}
			catch (\Throwable)
			{
				// neither the text of an item nor the title of a check list ever reaches the tracking
				// service: a log of the workflow is readable by anyone who sees the process
				$this->WriteToTrackingService(
					Loc::getMessage(
						'BPSA_CHECK_LIST_ITEM_ADD_ERROR_MSGVER_1',
						['#NUMBER#' => (string)$number, '#LIST_NUMBER#' => (string)$listNumber]
					),
					0,
					CBPTrackingType::Error
				);
			}
		}
	}

	/**
	 * The check lists a stored value carries: an element opening with the service prefix starts a new one,
	 * the elements after it are its items, and items met before any title form the first check list.
	 * A value with no title at all is the format every template saved before the feature holds, and it
	 * parses into exactly one check list with `hasTitle` false - a state the composing side has to keep
	 * apart from a title whose text is empty, or a round trip through the dialog would rewrite the value.
	 *
	 * Grouping is read from the template, before any expression is substituted: text returned by an
	 * expression never opens a check list, whatever it starts with.
	 *
	 * @return list<array{hasTitle: bool, title: string, items: list<string>}>
	 */
	public static function parseCheckLists($values): array
	{
		$lists = [];
		$current = null;

		foreach (static::reduceCheckListItemsToStrings($values) as $raw)
		{
			if (str_starts_with($raw, static::CHECK_LIST_TITLE_PREFIX))
			{
				$lists[] = [
					'hasTitle' => true,
					'title' => mb_substr($raw, mb_strlen(static::CHECK_LIST_TITLE_PREFIX)),
					'items' => [],
				];
				$current = array_key_last($lists);

				continue;
			}

			if ($current === null)
			{
				$lists[] = ['hasTitle' => false, 'title' => '', 'items' => []];
				$current = array_key_last($lists);
			}

			$lists[$current]['items'][] = static::unescapeCheckListItem($raw);
		}

		return $lists;
	}

	/**
	 * The check lists a request of the form carries, read off its two channels (TPL-01): the kind of a
	 * row decides what it becomes, its text never does. A row the kinds channel does not reach stays an
	 * item - a request whose channels differ in length is refused on saving anyway
	 * (see composeCheckListItemsValue()), and the dialog redrawn with that refusal keeps every heading
	 * the user typed instead of silently turning the names into items of the check list before them.
	 *
	 * @return list<array{hasTitle: bool, title: string, items: list<string>}>
	 */
	public static function readCheckListForm($texts, $kinds): array
	{
		$texts = static::readCheckListChannel($texts);
		$kinds = static::readCheckListChannel($kinds);

		$lists = [];
		foreach ($texts as $position => $rawText)
		{
			$text = static::reduceCheckListItemToString($rawText);

			if (($kinds[$position] ?? null) === static::CHECK_LIST_TITLE_KIND)
			{
				$lists[] = ['hasTitle' => true, 'title' => $text, 'items' => []];

				continue;
			}

			if ($text === '')
			{
				continue;
			}

			if ($lists === [])
			{
				$lists[] = ['hasTitle' => false, 'title' => '', 'items' => []];
			}

			$lists[array_key_last($lists)]['items'][] = $text;
		}

		return $lists;
	}

	/**
	 * The value of the property built from the two channels of the form (TPL-01): the kind of a row
	 * decides what it becomes, its text never does. An empty title is printed as well - dropping it
	 * would merge a check list into the one before it.
	 *
	 * @param list<string> $texts
	 * @param list<string> $kinds
	 * @return list<string>
	 */
	private static function composeCheckListValues(array $texts, array $kinds): array
	{
		$values = [];

		foreach ($texts as $position => $text)
		{
			$values[] = ($kinds[$position] ?? null) === static::CHECK_LIST_TITLE_KIND
				? static::CHECK_LIST_TITLE_PREFIX . $text
				: static::escapeCheckListItem($text);
		}

		return $values;
	}

	private static function escapeCheckListItem(string $text): string
	{
		return preg_match(static::makeCheckListPrefixPattern('*'), $text) === 1
			? static::CHECK_LIST_ESCAPE . $text
			: $text;
	}

	private static function unescapeCheckListItem(string $text): string
	{
		return preg_match(static::makeCheckListPrefixPattern('+'), $text) === 1
			? mb_substr($text, mb_strlen(static::CHECK_LIST_ESCAPE))
			: $text;
	}

	private static function makeCheckListPrefixPattern(string $quantifier): string
	{
		return '/^'
			. preg_quote(static::CHECK_LIST_ESCAPE, '/')
			. $quantifier
			. preg_quote(static::CHECK_LIST_TITLE_PREFIX, '/')
			. '/';
	}

	private static function extractUserField(array $field, array $currentValues): \Bitrix\Main\Result
	{
		$fieldValue = $currentValues[$field['FieldName']] ?? null;
		$fieldValueText = $currentValues["{$field['FieldName']}_text"] ?? null;

		if(CBPHelper::isEmptyValue($fieldValue) && !CBPHelper::isEmptyValue($fieldValueText))
		{
			$fieldValue = $fieldValueText;
		}

		$result = new \Bitrix\Main\Result();

		$result->setData(['value' => $fieldValue]);

		if($field['Required'])
		{
			if (
				($field['Multiple'] && (!$fieldValue || CBPHelper::isEmptyValue($fieldValue)))
				|| (!$field['Multiple'] && $fieldValue === '' && $field['Type'] !== FieldType::BOOL))
			{
				$result->addError(
					new \Bitrix\Main\Error(
						Loc::getMessage(
							'BPSNMA_EMPTY_REQUIRED_PROPERTY',
							['#PROPERTY_NAME#' => $field['Name']]
						)
					)
				);
			}
		}

		return $result;
	}

	public static function validateProperties($testProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		$testProperties = array_merge($testProperties['Fields'] ?? [], $testProperties);

		$map = static::getPropertiesDialogMap();
		$map = array_merge($map['Fields']['Map'], $map);
		unset($map['Fields']);

		foreach ($map as $propertyKey => $fieldProperties)
		{
			if(
				\CBPHelper::getBool($fieldProperties['Required'] ?? false)
				&& \CBPHelper::isEmptyValue($testProperties[$propertyKey] ?? null)
			)
			{
				if (
					$propertyKey === 'RESPONSIBLE_ID'
					&& !\CBPHelper::isEmptyValue($testProperties['FLOW_ID'] ?? null)
				)
				{
					continue;
				}
				$errors[] = [
					'code' => 'NotExist',
					'parameter' => 'FieldValue',
					'message' => Loc::getMessage(
						'BPSNMA_EMPTY_REQUIRED_PROPERTY',
						['#PROPERTY_NAME#' => $fieldProperties['Name']]
					),
				];
			}
		}

		return array_merge($errors, parent::validateProperties($testProperties, $user));
	}

	protected static function getPropertiesDialogMap(): array
	{
		$aiDescriptionProperty = class_exists(ActivityAiPropertyConverter::class)
			? ActivityAiPropertyConverter::PROPERTY_FIELD_AI_DESCRIPTION
			: 'AiDescription'
		;

		return [
			'Fields' => [
				'FieldName' => 'Fields',
				'Map' => static::getTaskFieldsMap(),
				$aiDescriptionProperty => 'Created task fields',
				'Getter' => function($dialog, $property, $currentActivity, $compatible) {
					$fields = $currentActivity['Properties']['Fields'];
					$files = $fields['UF_TASK_WEBDAV_FILES'] ?? null;

					if(
						isset($files)
						&& is_array($files)
						&& Loader::includeModule('disk')
						&& \Bitrix\Disk\Configuration::isSuccessfullyConverted()
					)
					{
						foreach($files as $key => $fileId)
						{
							if(!empty($fileId) && is_string($fileId) && mb_substr($fileId, 0, 1) != 'n')
							{
								$item = \Bitrix\Disk\Internals\FileTable::getList([
									'select' => ['ID'],
									'filter' => [
										'=XML_ID' => $fileId,
										'TYPE' => \Bitrix\Disk\Internals\FileTable::TYPE_FILE,
									],
								])->fetchObject();

								if($item)
								{
									$files[$key] = 'n' . $item->getId();
								}
							}
						}

						$fields['UF_TASK_WEBDAV_FILES'] = $files;
					}

					return $fields;
				},
			],
			'HoldToClose' => [
				'Name' => Loc::getMessage('BPTA1A_HOLD_TO_CLOSE'),
				'FieldName' => 'HOLD_TO_CLOSE',
				'Type' => FieldType::BOOL,
				'Required' => true,
				'Default' => false,
			],
			'AUTO_LINK_TO_CRM_ENTITY' => [
				'Name' => Loc::getMessage('BPTA1A_FIELD_NAME_AUTO_LINK_TO_CRM_ENTITY'),
				'FieldName' => 'AUTO_LINK_TO_CRM_ENTITY',
				'Type' => FieldType::BOOL,
				'Default' => false,
			],
			'AsChildTask' => [
				'Name' => Loc::getMessage('BPTA1A_FIELD_NAME_AS_CHILD_TASK'),
				'FieldName' => 'AS_CHILD_TASK',
				'Type' => FieldType::BOOL,
				'Default' => false,
			],
			'CheckListItems' => [
				'Name' => Loc::getMessage('BPSA_CHECK_LIST_ITEMS'),
				'FieldName' => 'CHECK_LIST_ITEMS',
				'Type' => FieldType::STRING,
				'Multiple' => true,
			],
			'TimeEstimateHour' => [
				'Name' => Loc::getMessage('BPTA1A_TIME_TRACKING_H'),
				'FieldName' => 'TIME_ESTIMATE_H',
				'Type' => FieldType::INT,
			],
			'TimeEstimateMin' => [
				'Name' => Loc::getMessage('BPTA1A_TIME_TRACKING_M'),
				'FieldName' => 'TIME_ESTIMATE_M',
				'Type' => FieldType::INT,
			],
		];
	}

	private static function getTaskFieldsMap(): array
	{
		if (!Loader::includeModule('tasks'))
		{
			return [];
		}

		$fields = [
			'TITLE' => [
				'Name' => Loc::getMessage('BPTA1A_TASKNAME'),
				'FieldName' => 'TITLE',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Editable' => true,
				'Required' => true,
				'Multiple' => false,
			],
			'CREATED_BY' => [
				'Name' => Loc::getMessage('BPTA1A_TASKCREATEDBY'),
				'FieldName' => 'CREATED_BY',
				'Type' => FieldType::USER,
				'Editable' => true,
				'Required' => true,
				'Multiple' => false,
				'Default' => 'author',
				'Settings' => [
					'allowEmailUsers' => true,
				],
			],
			'RESPONSIBLE_ID' => [
				'Name' => Loc::getMessage('BPTA1A_TASKASSIGNEDTO_V2'),
				'FieldName' => 'RESPONSIBLE_ID',
				'Type' => FieldType::USER,
				'Editable' => true,
				'Required' => true,
				'Multiple' => false,
				'Default' => 'author',
				'Settings' => [
					'allowEmailUsers' => true,
				],
			],
			'DESCRIPTION' => [
				'Name' => Loc::getMessage('BPTA1A_TASKDETAILTEXT'),
				'FieldName' => 'DESCRIPTION',
				'Type' => FieldType::TEXT,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'ACCOMPLICES' => [
				'Name' => Loc::getMessage('BPTA1A_TASKACCOMPLICES'),
				'FieldName' => 'ACCOMPLICES',
				'Type' => FieldType::USER,
				'Editable' => true,
				'Required' => false,
				'Multiple' => true,
				'Settings' => [
					'allowEmailUsers' => true,
				],
			],
			'START_DATE_PLAN' => [
				'Name' => Loc::getMessage('BPTA1A_TASKACTIVEFROM'),
				'FieldName' => 'START_DATE_PLAN',
				'Type' => FieldType::DATETIME,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'END_DATE_PLAN' => [
				'Name' => Loc::getMessage('BPTA1A_TASKACTIVETO'),
				'FieldName' => 'END_DATE_PLAN',
				'Type' => FieldType::DATETIME,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'DEADLINE' => [
				'Name' => Loc::getMessage('BPTA1A_TASKDEADLINE'),
				'FieldName' => 'DEADLINE',
				'Type' => FieldType::DATETIME,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'PRIORITY' => [
				'Name' => Loc::getMessage('BPTA1A_TASKPRIORITY_V3'),
				'FieldName' => 'PRIORITY',
				'Type' => FieldType::SELECT,
				'Options' => [
					Tasks\Internals\Task\Priority::AVERAGE => Loc::getMessage('TASKS_COMMON_NO'),
					Tasks\Internals\Task\Priority::HIGH => Loc::getMessage('TASKS_COMMON_YES'),
				],
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'GROUP_ID' => [
				'Name' => Loc::getMessage('BPTA1A_TASKGROUPID'),
				'FieldName' => 'GROUP_ID',
				'Type' => FieldType::SELECT,
				'Options' => static::fetchTaskGroups(),
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 0,
			],
			'FLOW_ID' => [
				'Name' => Loc::getMessage('BPTA1A_TASK_FLOW_ID'),
				'FieldName' => 'FLOW_ID',
				'Type' => FieldType::SELECT,
				'Options' => static::fetchTaskFlows(),
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 0,
			],
			'ALLOW_CHANGE_DEADLINE' => [
				'Name' => Loc::getMessage('BPTA1A_CHANGE_DEADLINE_MSGVER_1'),
				'FieldName' => 'ALLOW_CHANGE_DEADLINE',
				'Type' => FieldType::BOOL,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 'Y',
				'Settings' => [
					'display' => 'checkbox',
				],
			],
			'ALLOW_TIME_TRACKING' => [
				'Name' => Loc::getMessage('BPTA1A_ALLOW_TIME_TRACKING'),
				'FieldName' => 'ALLOW_TIME_TRACKING',
				'Type' => FieldType::BOOL,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 'Y',
				'Settings' => [
					'display' => 'checkbox',
				],
			],
			'TASK_CONTROL' => [
				'Name' => Loc::getMessage('BPTA1A_CHECK_RESULT_V2'),
				'FieldName' => 'TASK_CONTROL',
				'Type' => FieldType::BOOL,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 'Y',
				'Settings' => [
					'display' => 'checkbox',
				],
			],
			'AUDITORS' => [
				'Name' => Loc::getMessage('BPTA1A_TASKTRACKERS'),
				'FieldName' => 'AUDITORS',
				'Type' => FieldType::USER,
				'Editable' => true,
				'Required' => false,
				'Multiple' => true,
				'Settings' => [
					'allowEmailUsers' => true,
				],
			],
			'PARENT_ID' => [
				'Name' => Loc::getMessage('BPTA1A_MAKE_SUBTASK'),
				'FieldName' => 'PARENT_ID',
				'Type' => FieldType::INT,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
			],
			'TAG_NAMES' => [
				'Name' => Loc::getMessage('BPTA1A_FIELD_NAME_TAGS'),
				'FieldName' => 'TAG_NAMES',
				'Type' => FieldType::STRING,
				'Editable' => true,
				'Required' => false,
				'Multiple' => true,
			],
			'REQUIRED_RESULT' => [
				'Name' => Loc::getMessage('BPTA1A_REQUIRE_RESULT'),
				'FieldName' => 'REQUIRED_RESULT',
				'Type' => FieldType::BOOL,
				'Editable' => true,
				'Required' => false,
				'Multiple' => false,
				'Default' => 'N',
				'Settings' => [
					'display' => 'checkbox',
				],
			],
			'DEPENDS_ON' => [
				'Name' => Loc::getMessage('BPTA1A_FIELD_NAME_DEPENDS_ON'),
				'FieldName' => 'DEPENDS_ON',
				'Type' => FieldType::INT,
				'Editable' => true,
				'Multiple' => true,
			],
		];

		foreach (\Bitrix\Tasks\Util\Userfield\Task::getScheme() as $field)
		{
			if ($field['USER_TYPE_ID'] === 'mail_message')
			{
				continue;
			}

			$fields[$field['FIELD_NAME']] = [
				'Name' => $field['EDIT_FORM_LABEL'] ?: $field['USER_TYPE']['DESCRIPTION'],
				'FieldName' => $field['FIELD_NAME'],
				'Type' => $field['USER_TYPE_ID'] === 'boolean' ? FieldType::BOOL : $field['USER_TYPE_ID'],
				'Editable' => true,
				'Required' => ($field['MANDATORY'] == 'Y'),
				'Multiple' => ($field['MULTIPLE'] == 'Y'),
				'BaseType' => $field['USER_TYPE_ID'] === 'boolean' ? 'bool' : $field['USER_TYPE_ID'],
				'UserField' => $field,
			];
		}

		return $fields;
	}

	private static function fetchTaskGroups(): array
	{
		$groups = [Loc::getMessage('TASK_EMPTY_GROUP')];

		if (Loader::includeModule('socialnetwork'))
		{
			$groupIterator = CSocNetGroup::GetList(
				['NAME' => 'ASC'],
				['ACTIVE' => 'Y'],
				false,
				false,
				['ID', 'NAME']
			);
			while ($group = $groupIterator->GetNext())
			{
				$groups[$group['ID']] = "[{$group['ID']}]" . htmlspecialcharsback(Emoji::decode($group['NAME']));
			}
		}

		return $groups;
	}

	public static function fetchTaskFlows(): array
	{
		$flows = self::getFlows();
		$flowOptions = [];

		foreach ($flows as $flow)
		{
			$flowId = $flow->getId();
			$flowOptions[$flowId] = "[{$flowId}]" . htmlspecialcharsbx($flow->getName());
		}

		return $flowOptions;
	}

	/**
	 * @return Array<int, string> - [flowId => distributionType]
	 */
	public static function getFlowDistributionMap(): array
	{
		$flows = self::getFlows();
		$flowDistributionMap = [];

		foreach ($flows as $flow)
		{
			$flowDistributionMap[$flow->getId()] = $flow->getDistributionType()->value;
		}

		return $flowDistributionMap;
	}

	/**
	 * @return Bitrix\Tasks\Flow\Flow[]
	 */
	private static function getFlows(): array
	{
		static $flows = null;

		if ($flows)
		{
			return $flows;
		}

		$flows = [];

		$provider = new FlowProvider();
		$query = new ExpandedFlowQuery();

		$query
			->setSelect(['ID', 'NAME', 'DISTRIBUTION_TYPE'])
			->whereActive(true)
			->setAccessCheck(false);

		$flowCollection = $provider->getList($query);
		$flows = $flowCollection->getFlows();

		return $flows;
//		return $provider->getList($query)->getFlows();
	}

	private function checkCycling(array $documentId)
	{
		//check tasks robots only.
		if ($documentId[0] !== 'tasks')
		{
			return true;
		}

		$key = $this->GetName();

		if (!isset(self::$cycleCounter[$key]))
		{
			self::$cycleCounter[$key] = 0;
		}

		self::$cycleCounter[$key]++;
		if (self::$cycleCounter[$key] > self::CYCLE_LIMIT)
		{
			$this->WriteToTrackingService(Loc::getMessage('BPSA_CYCLING_ERROR_1'), 0, CBPTrackingType::Error);
			throw new Exception();
		}
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$fields = static::getTaskFieldsMap();
		$fields['TAGS'] = $fields['TAG_NAMES'];
		unset($fields['TAG_NAMES']);
		$fields['DEPENDS_ON']['Type'] = FieldType::STRING;

		$bpOptions = [
			'HoldToClose' => [
				'Name' => Loc::getMessage('BPTA1A_HOLD_TO_CLOSE'),
				'FieldName' => 'HOLD_TO_CLOSE',
				'Type' => \Bitrix\Bizproc\FieldType::BOOL,
				'BaseType' => \Bitrix\Bizproc\FieldType::BOOL,
				'Required' => true,
				'Default' => 'N',
			],
		];

		return array_merge($fields, $bpOptions);
	}

	protected function getDebugInfo(array $values = [], array $map = []): array
	{
		$onlyDesignerFields = ['AUTO_LINK_TO_CRM_ENTITY', 'UF_CRM_TASK', 'UF_TASK_WEBDAV_FILES'];
		$mustBeInBPUserStyle = ['CREATED_BY', 'RESPONSIBLE_ID', 'ACCOMPLICES', 'AUDITORS'];

		if (count($map) <= 0)
		{
			$map = static::getPropertiesMap($this->getDocumentType());
		}

		$fields = $this->Fields;

		foreach ($map as $key => $property)
		{
			if (array_key_exists($key, $values))
			{
				// temporary
				if (in_array($key, $onlyDesignerFields))
				{
					unset($map[$key]);
				}

				//hack
				if (in_array($key, $mustBeInBPUserStyle))
				{
					$values[$key] = $fields[$key];
				}

				continue;
			}

			// SE_PARAMETER
			if (
				$key === 'REQUIRED_RESULT'
				&& isset($values['SE_PARAMETER'][Tasks\Internals\Task\ParameterTable::PARAM_RESULT_REQUIRED])
			)
			{
				$values['REQUIRED_RESULT'] =
					$values['SE_PARAMETER'][Tasks\Internals\Task\ParameterTable::PARAM_RESULT_REQUIRED]['VALUE'];

				continue;
			}

			unset($map[$key]);
		}

		if (is_array($fields['DEPENDS_ON'] ?? null))
		{
			$map['DEPENDS_ON']['TrackValue'] = [];
			foreach ($fields['DEPENDS_ON'] as $taskId)
			{
				$task = Tasks\Internals\TaskTable::getById($taskId)->fetchObject();
				if ($task)
				{
					$map['DEPENDS_ON']['TrackValue'][] = $task->getTitle();
				}
			}
		}

		unset($values['SE_PARAMETER']);

		return parent::getDebugInfo($values, $map);
	}

	private function logDebugTaskUrl(int $taskId)
	{
		/** @var CBPDocumentService $documentService*/
		$documentService = $this->workflow->getService('DocumentService');

		$url = $documentService->getDocumentAdminPage(
			Tasks\Integration\Bizproc\Document\Task::resolveDocumentId($taskId)
		);

		$toWrite = [
			'propertyName' => Loc::getMessage('BPTA1A_TASK_URL_NAME'),
			'propertyValue' => $url,
			'propertyLinkName' => Loc::getMessage('BPTA1A_TASK_URL_LABEL'),
		];

		$this->writeDebugTrack(
			$this->getWorkflowInstanceId(),
			$this->getName(),
			$this->executionStatus,
			$this->executionResult,
			$this->getTitle(),
			$toWrite,
			CBPTrackingType::DebugLink,
		);
	}
}
