<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Crm;
use Bitrix\Crm\Integration\BizProc\Starter\CrmStarter;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\DocumentDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;
use Bitrix\Main\Localization\Loc;

class CBPCrmCopyDealActivity extends CBPActivity
{
	private static array $cycleCounter = [];
	public const CYCLE_LIMIT = 3;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'DealTitle' => '',
			'CategoryId' => 0,
			'StageId' => null,
			'Responsible' => null,
			'CopyTimeline' => 'N',

			//return
			'DealId' => 0,
		];

		$this->SetPropertiesTypes([
			'DealId' => [
				'Type' => 'int',
			],
		]);
	}

	protected function reInitialize()
	{
		parent::ReInitialize();
		$this->DealId = 0;
	}

	public function execute()
	{
		if (!CModule::IncludeModule('crm'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$documentId = $this->getDocumentId();
		$this->checkCycling($documentId);

		$sourceDealId = explode('_', $documentId[2])[1];
		$sourceFields = $fields = [];

		if ($sourceDealId > 0)
		{
			$dbResult = \CCrmDeal::GetListEx(
				[],
				['=ID' => $sourceDealId, 'CHECK_PERMISSIONS' => 'N'],
				false,
				false,
				['*', 'UF_*']
			);
			$sourceFields = $dbResult->Fetch();
		}

		if (!$sourceFields)
		{
			$this->WriteToTrackingService(
				Loc::getMessage('CRM_CDA_NO_SOURCE_FIELDS'),
				0,
				CBPTrackingType::Error
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->prepareSourceFields($sourceFields);

		$merger = new Crm\Merger\DealMerger(1, false);
		$merger->mergeFields($sourceFields, $fields, true);

		$responsibles = CBPHelper::ExtractUsers($this->Responsible, $this->GetDocumentId());
		if (count($responsibles) > 1)
		{
			shuffle($responsibles);
		}
		elseif (!$responsibles)
		{
			$responsibles[] = $sourceFields['ASSIGNED_BY_ID'];
		}

		$dealTitle = $this->DealTitle;
		if (empty($dealTitle))
		{
			$dealTitle = Loc::getMessage('CRM_CDA_NEW_DEAL_TITLE', ['#SOURCE_TITLE#' => $sourceFields['TITLE']]);
		}

		unset($fields['STAGE_ID']);
		$fields['CATEGORY_ID'] = (int)$this->CategoryId;

		$stageId = (string)$this->StageId;
		if ($this->workflow->isDebug())
		{
			$this->writeDebugInfo($this->getDebugInfo([
				'DealTitle' => $dealTitle,
				'Responsible' => 'user_' . (int)$responsibles[0],
			]));
		}

		if ($stageId)
		{
			$fields['STAGE_ID'] = $stageId;

			if ($fields['CATEGORY_ID'] !== Crm\Category\DealCategory::resolveFromStageID($stageId))
			{
				$this->WriteToTrackingService(
					Loc::getMessage("CRM_CDA_STAGE_SELECTION_ERROR"),
					0,
					CBPTrackingType::Error
				);

				return CBPActivityExecutionStatus::Closed;
			}
		}

		$fields['TITLE'] = $dealTitle;
		$fields['ASSIGNED_BY_ID'] = $responsibles[0];
		$fields['CONTACT_IDS'] = Crm\Binding\DealContactTable::getDealContactIDs($sourceDealId);

		$entity = new \CCrmDeal(false);
		$newDealId = $entity->Add(
			$fields,
			true,
			[
				'REGISTER_SONET_EVENT' => true,
				'CURRENT_USER' => 0,
				'DISABLE_REQUIRED_USER_FIELD_CHECK' => true,
			]
		);

		if (!$newDealId)
		{
			$this->WriteToTrackingService(
				strip_tags($entity->LAST_ERROR),
				0,
				CBPTrackingType::Error
			);

			return CBPActivityExecutionStatus::Closed;
		}

		$this->DealId = $newDealId;

		$this->markCreationEntryAsClone((int)$newDealId, (int)$sourceDealId);

		$parents = self::$cycleCounter[$documentId[2]];
		$parents[$documentId[2]] = $this->getName();
		self::$cycleCounter['DEAL_' . $newDealId] = $parents;

		$oldProducts = \CCrmProductRow::LoadRows('D', $sourceDealId, true);
		foreach ($oldProducts as $i => $product)
		{
			unset($oldProducts[$i]['ID'], $oldProducts[$i]['OWNER_ID'], $oldProducts[$i]['XML_ID']);
		}

		if (!CCrmProductRow::SaveRows('D', $newDealId, $oldProducts))
		{
			$this->WriteToTrackingService(
				Loc::getMessage('CRM_CDA_COPY_PRODUCTS_ERROR'),
				0,
				CBPTrackingType::Error
			);
		}

		if ($this->CopyTimeline === 'Y')
		{
			$this->attachTimeline((int)$sourceDealId, (int)$newDealId);
			$this->addCopiedLogMessage((int)$sourceDealId, (int)$newDealId);
		}

		$starter = new CrmStarter(new DocumentDto(\CCrmOwnerType::Deal, (int)$newDealId));
		$starter
			->setContextModuleId('bizproc')
			->runOnInnerDocumentAdd(
				new RunDataDto(
					actualFields: $fields,
				)
			)
		;

		return CBPActivityExecutionStatus::Closed;
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$arErrors = [];

		return array_merge($arErrors, parent::validateProperties($arTestProperties, $user));
	}

	public static function GetPropertiesDialog(
		$documentType,
		$activityName,
		$arWorkflowTemplate,
		$arWorkflowParameters,
		$arWorkflowVariables,
		$arCurrentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = ''
	)
	{
		if (!CModule::IncludeModule('crm'))
		{
			return '';
		}

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(
			__FILE__,
			[
				'documentType' => $documentType,
				'activityName' => $activityName,
				'workflowTemplate' => $arWorkflowTemplate,
				'workflowParameters' => $arWorkflowParameters,
				'workflowVariables' => $arWorkflowVariables,
				'currentValues' => $arCurrentValues,
				'formName' => $formName,
				'siteId' => $siteId,
			]
		);

		$dialog->setMap(
			static::getPropertiesMap(
				$documentType,
				['useRobotTitle' => $formName === 'bizproc_automation_robot_dialog']
			)
		);

		return $dialog;
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$defaultTitle = GetMessage('CRM_CDA_NEW_DEAL_TITLE', ['#SOURCE_TITLE#' => '{=Document:TITLE}']);
		if (isset($context['useRobotTitle']) && $context['useRobotTitle'] === true)
		{
			$defaultTitle = Crm\Automation\Helper::convertExpressions($defaultTitle, $documentType);
		}

		return [
			'DealTitle' => [
				'Name' => Loc::getMessage('CRM_CDA_DEAL_TITLE'),
				'FieldName' => 'deal_title',
				'Type' => 'string',
				'Default' => $defaultTitle,
			],
			'CategoryId' => [
				'Name' => Loc::getMessage('CRM_CDA_MOVE_TO_CATEGORY'),
				'FieldName' => 'category_id',
				'Type' => 'deal_category',
				'Required' => true,
			],
			'StageId' => [
				'Name' => Loc::getMessage('CRM_CDA_CHANGE_STAGE'),
				'FieldName' => 'stage_id',
				'Type' => 'deal_stage',
			],
			'Responsible' => [
				'Name' => Loc::getMessage('CRM_CDA_CHANGE_RESPONSIBLE'),
				'FieldName' => 'responsible',
				'Type' => 'user',
			],
			'CopyTimeline' => [
				'Name' => Loc::getMessage('CRM_CDA_COPY_TIMELINE'),
				'FieldName' => 'copy_timeline',
				'Type' => 'bool',
			],
		];
	}

	public static function GetPropertiesDialogValues(
		$documentType,
		$activityName,
		&$arWorkflowTemplate,
		&$arWorkflowParameters,
		&$arWorkflowVariables,
		$arCurrentValues,
		&$errors
	)
	{
		$errors = [];

		$properties = [
			'DealTitle' => $arCurrentValues['deal_title'],
			'CategoryId' => self::getCategoryId($arCurrentValues),
			'StageId' => $arCurrentValues['stage_id'],
			'Responsible' => CBPHelper::UsersStringToArray(
				$arCurrentValues['responsible'],
				$documentType,
				$errors
			),
			'CopyTimeline' => $arCurrentValues['copy_timeline'] === 'Y' ? 'Y' : 'N',
		];

		if ($properties['CategoryId'] === '' && static::isExpression($arCurrentValues['category_id_text']))
		{
			$properties['CategoryId'] = $arCurrentValues['category_id_text'];
		}
		if ($properties['StageId'] === '' && static::isExpression($arCurrentValues['stage_id_text']))
		{
			$properties['StageId'] = $arCurrentValues['stage_id_text'];
		}

		if (
			(!$properties['CategoryId'] || !$properties['StageId'])
			&& \Bitrix\Main\Loader::includeModule('crm')
		)
		{
			$entityTypeId = CCrmOwnerType::ResolveID($documentType[2]);
			$factory = Crm\Service\Container::getInstance()->getFactory($entityTypeId);

			if (isset($factory))
			{
				if ($properties['CategoryId'] === '' && $factory->isCategoriesSupported())
				{
					$category = $factory->createDefaultCategoryIfNotExist();
					$stage = $factory->getStages($category->getId())->getAll()[0];

					$properties['CategoryId'] = $category->getId();
					$properties['StageId'] = $stage->getStatusId();
				}
				elseif ($properties['StageId'] === '' && !is_null($factory->getCategory((int)$properties['CategoryId'])))
				{
					$stage = $factory->getStages((int)$properties['CategoryId'])->getAll()[0];

					$properties['StageId'] = $stage->getStatusId();
				}
			}
		}

		if (count($errors) > 0)
		{
			return false;
		}

		$errors = self::ValidateProperties($properties, new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser));
		if (count($errors) > 0)
		{
			return false;
		}

		$activity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$activity['Properties'] = $properties;

		return true;
	}

	protected static function getCategoryId($currentValues)
	{
		if($currentValues['category_id'] == '' && self::isExpression($currentValues['category_id_text']))
		{
			return $currentValues['category_id_text'];
		}
		return $currentValues['category_id'];
	}

	private function checkCycling(array $documentId): bool
	{
		//check deal only.
		if (!($documentId[0] === 'crm' && $documentId[1] === 'CCrmDocumentDeal'))
		{
			return true;
		}

		if (!array_key_exists($documentId[2], self::$cycleCounter))
		{
			self::$cycleCounter[$documentId[2]] = [];

			return true;
		}

		$key = $this->GetName();

		$parents = self::$cycleCounter[$documentId[2]];
		$countParentsActivity = array_count_values($parents);
		if ((int)($countParentsActivity[$key]) >= self::CYCLE_LIMIT)
		{
			$this->WriteToTrackingService(
				Loc::getMessage('CRM_CDA_CYCLING_ERROR'),
				0,
				CBPTrackingType::Error
			);

			throw new Exception(Loc::getMessage('CRM_CDA_CYCLING_EXCEPTION_MESSAGE'));
		}

		return true;
	}

	private function markCreationEntryAsClone(int $newDealId, int $sourceDealId): void
	{
		$row = Crm\Timeline\Entity\TimelineTable::getList(
			[
				'select' => ['ID', 'SETTINGS'],
				'filter' => [
					'=ASSOCIATED_ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
					'=ASSOCIATED_ENTITY_ID' => $newDealId,
					'=TYPE_ID' => Crm\Timeline\TimelineType::CREATION,
				],
				'limit' => 1,
			]
		)->fetch();

		if ($row)
		{
			$settings = is_array($row['SETTINGS']) ? $row['SETTINGS'] : [];
			$settings['BASE'] = [
				'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
				'ENTITY_ID' => $sourceDealId,
			];
			Crm\Timeline\Entity\TimelineTable::update(
				$row['ID'], [
					'TYPE_CATEGORY_ID' => Crm\Timeline\CreationEntry::CATEGORY_COPY,
					'SETTINGS' => $settings,
				]
			);
		}
	}

	private function getTimelineEntryTypesToAttach(): array
	{
		$exclude = [Crm\Timeline\TimelineType::UNDEFINED];
		$reflection = new \ReflectionClass(Crm\Timeline\TimelineType::class);
		$constants = $reflection->getConstants(\ReflectionClassConstant::IS_PUBLIC);
		$constants = array_filter($constants, 'is_int');

		return array_values(array_diff($constants, $exclude));
	}

	private function attachTimeline(int $sourceDealId, int $newDealId): void
	{
		$entityTypeId = \CCrmOwnerType::Deal;

		Crm\Timeline\Entity\TimelineBindingTable::attach(
			$entityTypeId,
			$sourceDealId,
			$entityTypeId,
			$newDealId,
			$this->getTimelineEntryTypesToAttach()
		);

		\CCrmActivity::AttachBinding($entityTypeId, $sourceDealId, $entityTypeId, $newDealId);

		$this->attachTaskBindings($sourceDealId, $newDealId);
		$this->attachCalendarBindings($sourceDealId, $newDealId);
	}

	private function attachTaskBindings(int $sourceDealId, int $newDealId): void
	{
		$rows = Crm\ActivityTable::query()
			->addSelect('ASSOCIATED_ENTITY_ID')
			->whereIn('PROVIDER_ID', [
				Crm\Activity\Provider\Task::getId(),
				Crm\Activity\Provider\Tasks\Task::getId(),
			])
			->where('BINDINGS.OWNER_TYPE_ID', \CCrmOwnerType::Deal)
			->where('BINDINGS.OWNER_ID', $sourceDealId)
			->where('ASSOCIATED_ENTITY_ID', '>', 0)
			->exec();

		$newBinding = [
			['OWNER_TYPE_ID' => \CCrmOwnerType::Deal, 'OWNER_ID' => $newDealId],
		];

		while ($row = $rows->fetch())
		{
			$taskId = (int)$row['ASSOCIATED_ENTITY_ID'];
			if ($taskId > 0)
			{
				Crm\Activity\Provider\Task::bindExternalEntity($taskId, $newBinding);
			}
		}
	}

	private function attachCalendarBindings(int $sourceDealId, int $newDealId): void
	{
		if (!\Bitrix\Main\Loader::includeModule('calendar'))
		{
			return;
		}

		$rows = Crm\ActivityTable::query()
			->addSelect('CALENDAR_EVENT_ID')
			->where('BINDINGS.OWNER_TYPE_ID', \CCrmOwnerType::Deal)
			->where('BINDINGS.OWNER_ID', $sourceDealId)
			->where('CALENDAR_EVENT_ID', '>', 0)
			->exec();

		$eventIds = [];
		while ($row = $rows->fetch())
		{
			$eventId = (int)$row['CALENDAR_EVENT_ID'];
			if ($eventId > 0)
			{
				$eventIds[] = $eventId;
			}
		}

		if (empty($eventIds))
		{
			return;
		}

		$newSlug = \CCrmOwnerTypeAbbr::ResolveByTypeID(\CCrmOwnerType::Deal) . '_' . $newDealId;

		foreach (array_chunk($eventIds, 50) as $chunk)
		{
			$events = \CCalendarEvent::GetList([
				'arFilter' => ['ID' => $chunk, '=DELETED' => 'N'],
				'arSelect' => ['ID'],
				'getUserfields' => true,
				'checkPermissions' => false,
			]);

			foreach ($events as $event)
			{
				$crmBindings = is_array($event['UF_CRM_CAL_EVENT']) ? $event['UF_CRM_CAL_EVENT'] : [];

				if (!in_array($newSlug, $crmBindings, true))
				{
					$crmBindings[] = $newSlug;
					\CCalendarEvent::UpdateUserFields((int)$event['ID'], ['UF_CRM_CAL_EVENT' => $crmBindings]);
				}
			}
		}
	}

	private function addCopiedLogMessage(int $sourceDealId, int $newDealId): void
	{
		Crm\Timeline\LogMessageController::getInstance()->onCreate(
			[
				'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
				'ENTITY_ID' => $sourceDealId,
				'SETTINGS' => [
					'COPY_TARGET' => [
						'ENTITY_TYPE_ID' => \CCrmOwnerType::Deal,
						'ENTITY_ID' => $newDealId,
					],
				],
			],
			Crm\Timeline\LogMessageType::COPIED
		);
	}

	private function prepareSourceFields(&$sourceFields)
	{
		unset($sourceFields['ORIGIN_ID'], $sourceFields['ORIGINATOR_ID']);

		if (
			!\Bitrix\Main\Loader::includeModule('calendar')
			|| !method_exists('\Bitrix\Calendar\UserField\ResourceBooking', 'prepareValue')
		)
		{
			return false;
		}

		$userFieldsList = CCrmDeal::GetUserFields();
		if (is_array($userFieldsList))
		{
			foreach ($userFieldsList as $userFieldName => $userFieldParams)
			{
				$fieldTypeId =
					isset($userFieldParams['USER_TYPE'])
						? $userFieldParams['USER_TYPE']['USER_TYPE_ID']
						: ''
				;
				$fieldValue = $sourceFields[$userFieldName] ?? null;

				if (!$fieldValue)
				{
					continue;
				}

				if ($fieldTypeId === 'resourcebooking')
				{
					$newValue = [];
					$resourceList = \Bitrix\Calendar\UserField\ResourceBooking::getResourceEntriesList((array)$fieldValue);

					if ($resourceList)
					{
						foreach ($resourceList['ENTRIES'] as $entry)
						{
							$newValue[] = \Bitrix\Calendar\UserField\ResourceBooking::prepareValue(
								$entry['TYPE'],
								$entry['RESOURCE_ID'],
								$resourceList['DATE_FROM'],
								MakeTimeStamp($resourceList['DATE_TO']) - MakeTimeStamp($resourceList['DATE_FROM']),
								$resourceList['SERVICE_NAME']
							);
						}
					}
					$sourceFields[$userFieldName] = $newValue;
				}
			}
		}

		return true;
	}
}
