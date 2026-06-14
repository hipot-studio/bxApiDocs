<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\Enum\SchedulerTransport;

class CBPWaitWorkDayActivity extends CBPActivity implements
	IBPEventActivity,
	IBPActivityExternalEventListener,
	IBPActivityDebugEventListener,
	IBPEventDrivenActivity,
	IBPConfigurableActivity
{
	private const START_EVENT_SORT = 92;
	private const CONTINUE_EVENT_SORT = 93;
	private const FIELD_USER_ID = 'USER_ID';
	private ?int $startEventId;
	private ?int $continueEventId;
	private ?string $schedulerTransport = null;

	public function __construct($name)
	{
		parent::__construct($name);
		$this->arProperties = [
			'Title' => '',
			'TargetUser' => null,
		];
	}

	public function cancel()
	{
		$this->Unsubscribe($this);

		return CBPActivityExecutionStatus::Closed;
	}

	public function execute()
	{
		if (!\Bitrix\Main\Loader::includeModule('timeman'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$result = $this->subscribe($this);

		if (!$result)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		return CBPActivityExecutionStatus::Executing;
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$userId = CBPHelper::extractUsers($this->TargetUser, $this->getDocumentId(), true);

		if (!$userId)
		{
			$this->trackError(GetMessage('BPWWD_ERROR_EMPTY_USER'));

			return false;
		}

		$schedule = $this->workflow->getRuntime()->getUserService()->getUserSchedule($userId);

		if ($schedule->getWorkDayStatus() === 'OPENED')
		{
			$this->writeToTrackingService(GetMessage('BPWWD_SUBSCRIBE_SKIPPED'));

			return false;
		}

		$schedulerService = $this->workflow->getService('SchedulerService');
		$this->schedulerTransport = $schedulerService->useMessengerTransport()
			? SchedulerTransport::Messenger->value
			: null
		;

		$this->startEventId = $schedulerService->subscribeOnEvent(
			$this->getWorkflowInstanceId(),
			$this->getName(),
			'timeman',
			'OnAfterTMDayStart',
			[self::FIELD_USER_ID => $userId],
			sort: self::START_EVENT_SORT,
			schedulerTransport: $this->getSchedulerTransport(),
		);

		$this->continueEventId = $schedulerService->subscribeOnEvent(
			$this->getWorkflowInstanceId(),
			$this->getName(),
			'timeman',
			'OnAfterTMDayContinue',
			[self::FIELD_USER_ID => $userId],
			sort: self::CONTINUE_EVENT_SORT,
			schedulerTransport: $this->getSchedulerTransport(),
		);

		$this->writeToTrackingService(
			GetMessage('BPWWD_SUBSCRIBED', ['#user#' => '{=user:user_' . $userId . '}'])
		);

		$this->workflow->addEventHandler($this->getName(), $eventHandler);

		return true;
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler)
	{
		$schedulerService = $this->workflow->GetService('SchedulerService');
		if (isset($this->startEventId))
		{
			$schedulerService->unSubscribeByEventId(
				$this->startEventId,
				self::FIELD_USER_ID,
				$this->getSchedulerTransport()
			);
		}
		if (isset($this->continueEventId))
		{
			$schedulerService->unSubscribeByEventId(
				$this->continueEventId,
				self::FIELD_USER_ID,
				$this->getSchedulerTransport()
			);
		}

		$this->startEventId = null;
		$this->continueEventId = null;

		$this->workflow->removeEventHandler($this->name, $eventHandler);
	}

	public function onExternalEvent($arEventParameters = [])
	{
		if ($this->executionStatus != CBPActivityExecutionStatus::Closed)
		{
			if (!empty($arEventParameters['DebugEvent']))
			{
				$this->writeToTrackingService(
					GetMessage('BPWWD_DEBUG_EVENT'),
					0,
					CBPTrackingType::Debug
				);
			}

			$this->unsubscribe($this);
			$this->workflow->closeActivity($this);
		}
	}

	public function onDebugEvent(array $eventParameters = [])
	{
		$eventParameters['DebugEvent'] = true;
		$this->onExternalEvent($eventParameters);
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		$errors = [];

		if (empty($arTestProperties['TargetUser']))
		{
			$errors[] = [
				'code' => 'NotExist',
				'parameter' => 'TargetUser',
				'message' => GetMessage('BPWWD_PROP_ERROR_EMPTY_USER')
			];
		}

		return array_merge($errors, parent::ValidateProperties($arTestProperties, $user));
	}

	public static function getPropertiesDialog(
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
		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, [
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId,
		]);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			'TargetUser' => [
				'Name' => GetMessage('BPWWD_PROP_TARGET_USER'),
				'FieldName' => 'target_user',
				'Type' => \Bitrix\Bizproc\FieldType::USER,
				'Required' => true,
			],
		];
	}

	public static function getPropertiesDialogValues(
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
			'TargetUser' => CBPHelper::UsersStringToArray(
				$arCurrentValues['target_user'], $documentType, $errors
			),
		];

		$user = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$errors = self::ValidateProperties($properties, $user);

		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}

	private function getSchedulerTransport(): ?SchedulerTransport
	{
		return $this->schedulerTransport !== null
			? SchedulerTransport::tryFrom($this->schedulerTransport)
			: null
		;
	}
}
