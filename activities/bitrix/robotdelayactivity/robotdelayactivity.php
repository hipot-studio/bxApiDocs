<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\Enum\SchedulerTransport;

$runtime = CBPRuntime::GetRuntime();
$runtime->includeActivityFile('DelayActivity');

class CBPRobotDelayActivity extends CBPDelayActivity
{
	private const START_EVENT_SORT = 90;
	private const CONTINUE_EVENT_SORT = 91;
	private const FIELD_USER_ID = 'USER_ID';
	private ?int $startEventId;
	private ?int $continueEventId;
	private ?string $schedulerTransport = null;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties['WaitWorkDayUser'] = null;
	}

	public function execute()
	{
		$status = parent::execute();

		if ($status === CBPActivityExecutionStatus::Executing)
		{
			return $status;
		}

		return $this->subscribeOnDay() ? CBPActivityExecutionStatus::Executing : CBPActivityExecutionStatus::Closed;
	}

	public function cancel()
	{
		$this->unsubscribeOnDay();
		return parent::cancel();
	}

	public function OnExternalEvent($arEventParameters = [])
	{
		if ($this->executionStatus != CBPActivityExecutionStatus::Closed)
		{
			if (!empty($arEventParameters['DebugEvent']))
			{
				$this->writeToTrackingService(
					\Bitrix\Main\Localization\Loc::getMessage('BPDA_DEBUG_EVENT'),
					0,
					CBPTrackingType::Debug
				);

				$this->unsubscribe($this);
				$this->unsubscribeOnDay();
				$this->workflow->CloseActivity($this);

				return;
			}

			if ($arEventParameters['SchedulerService'] === 'OnAgent')
			{
				$this->Unsubscribe($this);

				if (!$this->subscribeOnDay())
				{
					$this->workflow->CloseActivity($this);
				}
			}
			else// work day event
			{
				$this->unsubscribeOnDay();
				$this->workflow->CloseActivity($this);
			}
		}
	}

	protected function subscribeOnDay(): bool
	{
		if (self::isWaitWorkDayAvailable())
		{
			$userId = CBPHelper::extractUsers($this->WaitWorkDayUser, $this->getDocumentId(), true);

			if (!$userId)
			{
				return false;
			}

			$schedule = $this->workflow->getRuntime()->getUserService()->getUserSchedule($userId);

			if ($schedule->getWorkDayStatus() === 'OPENED')
			{
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

			$this->logMessage(
				GetMessage('BPRDA_SUBSCRIBED', ['#user#' => '{=user:user_' . $userId . '}'])
			);

			$this->workflow->addEventHandler($this->getName(), $this);

			return true;
		}

		return false;
	}

	protected function unsubscribeOnDay()
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

		$this->workflow->removeEventHandler($this->name, $this);
	}

	public static function validateProperties($arTestProperties = [], CBPWorkflowTemplateUser $user = null)
	{
		if (!empty($arTestProperties['WaitWorkDayUser']))
		{
			return [];
		}

		return parent::validateProperties($arTestProperties, $user);
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
		$parentDialog = parent::getPropertiesDialog(...func_get_args());

		$dialog = new \Bitrix\Bizproc\Activity\PropertiesDialog(__FILE__, array(
			'documentType' => $documentType,
			'activityName' => $activityName,
			'workflowTemplate' => $arWorkflowTemplate,
			'workflowParameters' => $arWorkflowParameters,
			'workflowVariables' => $arWorkflowVariables,
			'currentValues' => $arCurrentValues,
			'formName' => $formName,
			'siteId' => $siteId
		));

		$dialog->setRuntimeData(['parentDialog' => $parentDialog]);

		if (self::isWaitWorkDayAvailable())
		{
			$dialog->setMap([
				'WaitWorkDayUser' => [
					'Name' => GetMessage('BPRDA_PROPERTY_WAIT_WORKDAY_USER_NAME'),
					'Type' => 'user',
					'FieldName' => 'wait_wd_user',
				]
			]);
		}

		return $dialog;
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
		$result = parent::GetPropertiesDialogValues(
			$documentType,
			$activityName,
			$arWorkflowTemplate,
			$arWorkflowParameters,
			$arWorkflowVariables,
			$arCurrentValues,
			$errors
		);

		if ($result && isset($arCurrentValues['wait_wd_user']))
		{
			$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($arWorkflowTemplate, $activityName);
			$currentActivity['Properties']['WaitWorkDayUser'] = CBPHelper::UsersStringToArray(
				$arCurrentValues['wait_wd_user'],
				$documentType,
				$errors
			);

			if ($errors)
			{
				return false;
			}
		}

		return $result;
	}

	private static function isWaitWorkDayAvailable(): bool
	{
		return \CBPHelper::isWorkTimeAvailable();
	}

	private function getSchedulerTransport(): ?SchedulerTransport
	{
		return $this->schedulerTransport !== null
			? SchedulerTransport::tryFrom($this->schedulerTransport)
			: null
		;
	}
}
