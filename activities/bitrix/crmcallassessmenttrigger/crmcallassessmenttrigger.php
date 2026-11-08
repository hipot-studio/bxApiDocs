<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseTrigger;
use Bitrix\Bizproc\Activity\Mixins\ExternalEventSubscriptionTrait;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Copilot\CallAssessment\AssessmentClientTypeResolver;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

class CBPCrmCallAssessmentTrigger extends BaseTrigger implements IBPEventActivity, IBPActivityExternalEventListener
{
	use ExternalEventSubscriptionTrait;

	private const MODULE_ID = 'ai';
	private const QUEUE_JOB_SUCCESS = 'onQueueJobExecute';
	private const QUEUE_JOB_FAIL = 'onQueueJobFail';
	private const LISTEN_EVENTS = [
		self::QUEUE_JOB_SUCCESS,
		self::QUEUE_JOB_FAIL,
	];
	private const TIMEOUT_SECONDS = 1200;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'ActivityId' => null,
			'AssessmentSettingsId' => null,
			'UserId' => null,
			'EventDateTime' => null,
			//return
			'Transcription' => null,
			'ClientType' => null,
			'CallType' => null,
		];

		$this->SetPropertiesTypes([
			'ActivityId' => ['Type' => FieldType::INT],
			'AssessmentSettingsId' => ['Type' => FieldType::INT],
			'UserId' => ['Type' => FieldType::INT],
			'EventDateTime' => ['Type' => FieldType::DATETIME],
			'Transcription' => ['Type' => FieldType::STRING],
			'ClientType' => ['Type' => FieldType::INT],
			'CallType' => ['Type' => FieldType::INT],
		]);
	}

	public function reInitialize(): void
	{
		parent::reInitialize();

		$this->reInitializeEventSubscription();
	}

	public function cancel(): int
	{
		$this->unsubscribe($this);

		return CBPActivityExecutionStatus::Closed;
	}

	public function checkApplyRules(array $rules, \Bitrix\Bizproc\Activity\Trigger\TriggerParameters $parameters): \Bitrix\Bizproc\Result
	{
		// This trigger is fired programmatically with explicit parameters and has no
		// configurable field conditions (getPropertiesMap() returns []), so it always applies.
		return \Bitrix\Bizproc\Result::createOk();
	}

	public function execute(): int
	{
		if (!Loader::includeModule('crm') || !Loader::includeModule('ai'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$eventData = $this->getEventData();

		$activityId = (int)($eventData['ActivityId'] ?? 0);
		$assessmentSettingsId = $eventData['AssessmentSettingsId'] ?? null;
		if ($assessmentSettingsId <= 0)
		{
			$assessmentSettingsId = null;
		}

		$userId = (int)($eventData['UserId'] ?? 0);

		if ($activityId <= 0)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->setProperties([
			'ActivityId' => $activityId,
			'AssessmentSettingsId' => $assessmentSettingsId,
			'UserId' => $userId,
			'EventDateTime' => (new DateTime())->format(DateTime::getFormat()),
		]);

		$transcriptionResult = JobRepository::getInstance()
			->getTranscribeCallRecordingResultByActivity($activityId)
		;

		// No job exists — launch transcription
		if ($transcriptionResult === null)
		{
			return $this->launchTranscription($activityId, $userId);  // @todo if $userId = 0 ?
		}

		// Job exists and is pending — wait for it
		if ($transcriptionResult->isPending())
		{
			return $this->subscribeAndWait($activityId);
		}

		// Job already completed successfully
		if ($transcriptionResult->isSuccess())
		{
			$this->setProperties([
				'ActivityId' => $activityId,
				'AssessmentSettingsId' => $assessmentSettingsId,
				'UserId' => $userId,
				'Transcription' => $transcriptionResult->getPayload()?->transcription ?? '',
				'ClientType' => $this->resolveClientType($activityId),
				'CallType' => $this->resolveCallType($activityId),
			]);

			return CBPActivityExecutionStatus::Closed;
		}

		// Job failed and retries are exhausted
		if ($transcriptionResult->isErrorsLimitExceeded())
		{
			foreach ($transcriptionResult->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return CBPActivityExecutionStatus::Closed;
		}

		// Job failed but retry budget remains — relaunch
		return $this->launchTranscription($activityId, $userId); // @todo if $userId = 0 ?
	}

	private function launchTranscription(int $activityId, int $userId): int
	{
		$launchResult = ServiceLocator::getInstance()->get(PipelineExecutor::class)->startOrResume(
			new StepContext(
				activityId: $activityId,
				userId: $userId ?: null,
				scenarioName: Scenario::CALL_SCORING_V2_SCENARIO,
				isManualLaunch: false,
				activityProvider: \Bitrix\Crm\Activity\Provider\Call::getId(),
			),
		);

		if (!$launchResult->isSuccess() || $launchResult->getJobId() === null)
		{
			foreach ($launchResult->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return CBPActivityExecutionStatus::Closed;
		}

		return $this->subscribeAndWait($activityId);
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		if ($this->eventSubscriptionHash === null)
		{
			throw new \LogicException(
				static::class . '::subscribe() called before event subscription hash was initialized.'
				. ' Use subscribeAndWait() to start the subscription.',
			);
		}

		$this->subscribeOnExternalEvents(
			self::MODULE_ID,
			self::LISTEN_EVENTS,
			$this->eventSubscriptionHash,
			self::TIMEOUT_SECONDS,
		);
		$this->workflow->addEventHandler($this->name, $eventHandler);
	}

	public function unsubscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		$this->unsubscribeFromExternalEvents(self::MODULE_ID, self::LISTEN_EVENTS);
		$this->workflow->removeEventHandler($this->name, $eventHandler);
	}

	public function onExternalEvent($arEventParameters = []): void
	{
		if ($this->isExternalEventIrrelevant($arEventParameters, self::LISTEN_EVENTS))
		{
			return;
		}

		if (!Loader::includeModule('crm') || !Loader::includeModule('ai'))
		{
			$this->trackError('Required modules crm and ai are not available');
			$this->unsubscribe($this);
			$this->workflow->cancelActivity($this);

			return;
		}

		if ($this->isExternalEventTimeout($arEventParameters))
		{
			$this->trackError('Transcription operation timed out');
			$this->unsubscribe($this);
			$this->workflow->cancelActivity($this);

			return;
		}

		$activityId = (int)$this->ActivityId;

		if ($arEventParameters['eventName'] === self::QUEUE_JOB_SUCCESS)
		{
			$aiResult = $this->extractEventObject($arEventParameters, \Bitrix\AI\Result::class);
			if ($aiResult !== null)
			{
				$this->Transcription = $aiResult->getPrettifiedData();
				$this->ClientType = $this->resolveClientType($activityId);
				$this->CallType = $this->resolveCallType($activityId);
			}
			else
			{
				$this->trackError('Unexpected empty transcription result');
			}
		}
		elseif ($arEventParameters['eventName'] === self::QUEUE_JOB_FAIL)
		{
			$error = $this->extractEventError($arEventParameters);
			if ($error instanceof \Bitrix\Main\Error)
			{
				$this->trackError($error->getMessage());
			}

			$this->unsubscribe($this);
			$this->workflow->cancelActivity($this);

			return;
		}

		$this->unsubscribe($this);
		$this->workflow->closeActivity($this);
	}

	private function subscribeAndWait(int $activityId): int
	{
		$pendingJob = JobRepository::getInstance()->getPendingJobByActivity($activityId, TranscribeCallRecording::TYPE_ID);
		if ($pendingJob === null)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->subscribeOnExternalEvents(
			self::MODULE_ID,
			self::LISTEN_EVENTS,
			$pendingJob->getHash(),
			self::TIMEOUT_SECONDS,
		);
		$this->workflow->addEventHandler($this->name, $this);

		// Re-check after subscribe to close the race window
		JobRepository::getInstance()->cleanRuntimeCache();
		$recheckResult = JobRepository::getInstance()->getTranscribeCallRecordingResultByActivity($activityId);

		if ($recheckResult !== null && !$recheckResult->isPending())
		{
			if ($recheckResult->isSuccess())
			{
				$this->Transcription = $recheckResult->getPayload()?->transcription ?? '';
				$this->ClientType = $this->resolveClientType($activityId);
				$this->CallType = $this->resolveCallType($activityId);
			}
			else
			{
				foreach ($recheckResult->getErrors() as $error)
				{
					$this->trackError($error->getMessage());
				}
			}
			$this->unsubscribe($this);

			return CBPActivityExecutionStatus::Closed;
		}

		return CBPActivityExecutionStatus::Executing;
	}

	private function resolveClientType(int $activityId): ?int
	{
		if ($activityId <= 0)
		{
			return null;
		}

		return (new AssessmentClientTypeResolver())
			->resolveByActivityId($activityId)
			?->value
		;
	}

	private function resolveCallType(int $activityId): ?int
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		if (!$activity)
		{
			return null;
		}

		$direction = (int)($activity['DIRECTION'] ?? 0);

		return match ($direction)
		{
			\CCrmActivityDirection::Incoming => CallType::INCOMING->value,
			\CCrmActivityDirection::Outgoing => CallType::OUTGOING->value,
			default => null,
		};
	}

	public static function getPropertiesDialog(
		$documentType,
		$activityName,
		$workflowTemplate,
		$workflowParameters,
		$workflowVariables,
		$currentValues = null,
		$formName = '',
		$popupWindow = null,
		$siteId = '',
	): string|PropertiesDialog
	{
		if (!Loader::includeModule('crm'))
		{
			return '';
		}

		$dialog = new PropertiesDialog(
			__FILE__,
			[
				'documentType' => $documentType,
				'activityName' => $activityName,
				'workflowTemplate' => $workflowTemplate,
				'workflowParameters' => $workflowParameters,
				'workflowVariables' => $workflowVariables,
				'currentValues' => $currentValues,
				'formName' => $formName,
				'siteId' => $siteId,
			],
		);

		$dialog->setMap(static::getPropertiesMap($documentType));

		return $dialog;
	}

	public static function getCallAssessmentOptions(): array
	{
		static $cache = null;

		if ($cache !== null)
		{
			return $cache;
		}

		$items = CopilotCallAssessmentController::getInstance()->getList([
			'select' => ['ID', 'TITLE'],
			'filter' => [
				'IS_ENABLED' => 'Y',
				'!=CRITERIA.ID' => null,
			],
		])->collectValues();

		$cache = [];
		foreach ($items as $item)
		{
			$cache[$item['ID']] = $item['TITLE'];
		}

		return $cache;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [];
	}

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$errors,
	): bool
	{
		$properties = [];

		$user = new CBPWorkflowTemplateUser(CBPWorkflowTemplateUser::CurrentUser);
		$errors = self::ValidateProperties($properties, $user);
		if ($errors)
		{
			return false;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}
}
