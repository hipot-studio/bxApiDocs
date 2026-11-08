<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\Mixins\ExternalEventSubscriptionTrait;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\SelectCallScoringScriptPayload;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class CBPCrmGetCallAssessmentActivity extends CBPActivity implements IBPEventActivity, IBPActivityExternalEventListener, IBPConfigurableActivity
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
	private const SCORING_RETRY_DELAY_SECONDS = 1;
	private const PHASE_SELECT_SCRIPT = 'selectScript';
	private const PHASE_SCORING = 'scoring';
	private const PHASE_WAIT_SCORING_SLOT = 'waitScoringSlot';
	private const PHASE_SCORING_RETRY = 'scoringRetry';
	private const WAIT_OUTCOME_PENDING = 'pending';
	private const WAIT_OUTCOME_DONE_SUCCESS = 'done_success';
	private const WAIT_OUTCOME_DONE_FAIL = 'done_fail';
	private const WAIT_OUTCOME_MISSING = 'missing';

	private string $phase = self::PHASE_SCORING;
	private ?int $jobId = null;

	public function __construct($name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',
			'AssessmentSettingsId' => [],
			'Transcription' => '',
			'ActivityId' => null,
			'UserId' => null,
			'LowBorder' => null,
			'HighBorder' => null,
			//return
			'CallQuality' => null,
		];

		$this->SetPropertiesTypes([
			'AssessmentSettingsId' => [
				'Type' => FieldType::ENTITYSELECTOR,
			],
			'Transcription' => [
				'Type' => FieldType::STRING,
			],
			'ActivityId' => [
				'Type' => FieldType::INT,
			],
			'UserId' => [
				'Type' => FieldType::INT,
			],
			'LowBorder' => [
				'Type' => FieldType::INT,
			],
			'HighBorder' => [
				'Type' => FieldType::INT,
			],
			'CallQuality' => [
				'Type' => FieldType::INT,
			],
		]);
	}

	public function reInitialize(): void
	{
		parent::reInitialize();

		$this->reInitializeEventSubscription();
		$this->phase = self::PHASE_SCORING;
		$this->jobId = null;
	}

	public function cancel(): int
	{
		$this->unsubscribe($this);

		return CBPActivityExecutionStatus::Closed;
	}

	public function execute(): int
	{
		if (!Loader::includeModule('crm') || !Loader::includeModule('ai'))
		{
			return CBPActivityExecutionStatus::Closed;
		}

		if (!AIManager::isCallScoringV2Enabled())
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$activityId = (int)$this->ActivityId;
		if ($activityId <= 0)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->syncBordersWithScripts();

		return $this->executeSelectScript($activityId);
	}

	private function executeSelectScript(int $activityId): int
	{
		if ($this->getAssessmentSettingsId() !== null)
		{
			return $this->executeScoring($activityId);
		}

		$pendingJob = JobRepository::getInstance()->getPendingJobByActivity($activityId, SelectCallScoreScript::TYPE_ID);
		if ($pendingJob === null)
		{
			$candidateIds = $this->resolveCandidateAssessmentSettingsIds($activityId);
			if (count($candidateIds) === 1)
			{
				// nothing left to choose between, so the call does not pay for a selector request
				$this->AssessmentSettingsId = $candidateIds;

				return $this->executeScoring($activityId);
			}

			$launchResult = $this->launchSelectScript($activityId, $candidateIds);
			$jobId = $launchResult?->getJobId();
			if ($jobId === null)
			{
				return CBPActivityExecutionStatus::Closed;
			}
		}
		else
		{
			$jobId = $pendingJob->getId();
		}

		$this->phase = self::PHASE_SELECT_SCRIPT;

		$outcome = $this->subscribeAndWaitForJob($jobId, SelectCallScoreScript::TYPE_ID);

		if ($outcome === self::WAIT_OUTCOME_PENDING)
		{
			return CBPActivityExecutionStatus::Executing;
		}

		if ($outcome === self::WAIT_OUTCOME_DONE_SUCCESS)
		{
			return $this->executeScoring($activityId);
		}

		if ($outcome === self::WAIT_OUTCOME_MISSING && !empty($this->AssessmentSettingsId))
		{
			return $this->executeScoring($activityId);
		}

		return CBPActivityExecutionStatus::Closed;
	}

	private function executeScoring(int $activityId): int
	{
		$pendingJob = JobRepository::getInstance()->getPendingJobByActivity($activityId, ScoreCallV2::TYPE_ID);
		if ($pendingJob === null)
		{
			$this->phase = self::PHASE_SCORING;
			$launchResult = $this->launchScoring($activityId);
			$jobId = $launchResult?->getJobId();
			if ($jobId === null)
			{
				return CBPActivityExecutionStatus::Closed;
			}
		}
		else
		{
			$this->phase = $this->isScoringJobForCurrentAssessment($pendingJob)
				? self::PHASE_SCORING
				: self::PHASE_WAIT_SCORING_SLOT;
			$jobId = $pendingJob->getId();
		}

		$outcome = $this->subscribeAndWaitForJob($jobId, ScoreCallV2::TYPE_ID);

		return $outcome === self::WAIT_OUTCOME_PENDING
			? CBPActivityExecutionStatus::Executing
			: CBPActivityExecutionStatus::Closed;
	}

	private function isScoringJobForCurrentAssessment(EO_Queue $job): bool
	{
		$assessmentSettingsId = $this->getAssessmentSettingsId();

		return $assessmentSettingsId !== null
			&& JobRepository::getInstance()->getCallAssessmentSettingsIdByJob($job) === $assessmentSettingsId;
	}

	// @todo need use new api
	private function launchScoring(int $activityId): ?\Bitrix\Crm\Integration\AI\Result
	{
		if (!AIManager::isAiCallProcessingEnabled())
		{
			$this->trackError(ErrorCode::getFeatureDisabledError()->getMessage());

			return null;
		}

		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment))
		{
			$this->trackError(
				AIErrorCode::getAIDisabledError([
					'sliderCode' => Scenario::CALL_SCORING_SCENARIO_SLIDER_CODE,
				])->getMessage(),
			);

			return null;
		}

		$assessmentSettingsId = $this->getAssessmentSettingsId();
		if ($assessmentSettingsId === null)
		{
			return null;
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($assessmentSettingsId);
		if ($entity === null)
		{
			$this->trackError(ErrorCode::getNotFoundError()->getMessage());

			return null;
		}

		$item = CallAssessmentItem::createFromEntity($entity);
		$checkerResult = CallAssessmentItemChecker::getInstance()
			->setItem($item)
			->run()
		;

		if (!$checkerResult->isSuccess())
		{
			$this->trackError($checkerResult->getError()->getMessage());

			return null;
		}

		$transcription = (string)$this->Transcription;
		if ($transcription === '')
		{
			$this->trackError('Transcription is empty');

			return null;
		}

		$launchResult = AIManager::launchScoreCallV2(
			$activityId,
			$transcription,
			$assessmentSettingsId,
			$this->getUserId(),
		);

		if (!$launchResult->isSuccess() || $launchResult->getJobId() === null)
		{
			foreach ($launchResult->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return null;
		}

		return $launchResult;
	}

	/**
	 * Candidates the script selector may choose between. An explicit list configured in the template wins -
	 * it is the choice of the user. Otherwise CRM decides which settings are admissible for this call, so
	 * the selector never sees a setting the schedule, the client type or the call type has already ruled
	 * out, and never has to guess among all the scripts of the portal either.
	 *
	 * @return int[]
	 */
	private function resolveCandidateAssessmentSettingsIds(int $activityId): array
	{
		$configuredIds = $this->getAssessmentSettingsIds();
		if (!empty($configuredIds))
		{
			return $configuredIds;
		}

		return ItemFactory::getCandidateIdsByActivityId(
			$activityId,
			requireScoringCriteria: true,
			requireCurrentAvailability: true,
		);
	}

	/**
	 * @param int[] $candidateIds
	 */
	// @todo need use new api
	private function launchSelectScript(int $activityId, array $candidateIds): ?\Bitrix\Crm\Integration\AI\Result
	{
		if (empty($candidateIds))
		{
			// scoring a call by whatever script the selector finds is worse than not scoring it: the report
			// would be built on criteria this call was never meant to be measured by
			$this->trackError('No call assessment setting is admissible for this call');

			return null;
		}

		$itemIdentifier = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);
		$typeId = SelectCallScoreScript::TYPE_ID;
		QueueTable::deleteByItem($itemIdentifier, $typeId);

		$launchResult = AIManager::launchSelectCallScoringScript(
			$activityId,
			(string)$this->Transcription,
			$this->getUserId(),
			assessmentSettingsIds: $candidateIds,
		);

		if (!$launchResult->isSuccess() || $launchResult->getJobId() === null)
		{
			foreach ($launchResult->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return null;
		}

		return $launchResult;
	}

	private function getUserId(): int
	{
		return $this->UserId;
	}

	private function syncBordersWithScripts(): void
	{
		if (!is_numeric($this->LowBorder) || !is_numeric($this->HighBorder))
		{
			return;
		}

		CopilotCallAssessmentController::getInstance()->syncBordersToAll(
			(int)$this->LowBorder,
			(int)$this->HighBorder,
		);
	}

	private function getAssessmentSettingsId(): ?int
	{
		$assessmentSettingsIds = $this->getAssessmentSettingsIds();

		return count($assessmentSettingsIds) === 1 ? $assessmentSettingsIds[0] : null;
	}

	private function getAssessmentSettingsIds(): array
	{
		if (!is_array($this->AssessmentSettingsId))
		{
			return [];
		}

		$assessmentSettingsIds = array_map('intval', $this->AssessmentSettingsId);
		$assessmentSettingsIds = array_filter($assessmentSettingsIds, static fn(int $id): bool => $id > 0);

		return array_values(array_unique($assessmentSettingsIds));
	}

	public function subscribe(IBPActivityExternalEventListener $eventHandler): void
	{
		if ($this->eventSubscriptionHash === null)
		{
			throw new \LogicException(
				static::class . '::subscribe() called before event subscription hash was initialized.'
				. ' Use subscribeAndWaitForJob()/subscribeAndWait() to start the subscription.',
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

		if ($this->phase === self::PHASE_SCORING_RETRY)
		{
			if ($this->isExternalEventTimeout($arEventParameters))
			{
				$this->unsubscribe($this);
				$result = $this->executeScoring((int)$this->ActivityId);
				if ($result === CBPActivityExecutionStatus::Closed)
				{
					$this->workflow->closeActivity($this);
				}
			}

			return;
		}

		if ($this->isExternalEventTimeout($arEventParameters))
		{
			$this->trackError(
				$this->phase === self::PHASE_SELECT_SCRIPT
					? 'Script selection operation timed out'
					: 'Scoring operation timed out',
			);
			$this->unsubscribe($this);
			$this->workflow->cancelActivity($this);

			return;
		}

		$eventName = $arEventParameters['eventName'] ?? '';
		if ($this->phase === self::PHASE_WAIT_SCORING_SLOT)
		{
			$this->unsubscribe($this);
			$this->scheduleScoringRetry();

			return;
		}

		if ($this->phase === self::PHASE_SELECT_SCRIPT)
		{
			$this->handleSelectScriptEvent($eventName, $arEventParameters);

			return;
		}

		if ($eventName === self::QUEUE_JOB_SUCCESS)
		{
			$aiResult = $this->extractEventObject($arEventParameters, \Bitrix\AI\Result::class);
			if ($aiResult !== null)
			{
				$this->CallQuality = ScoreCallV2::extractAssessmentFromAIResult($aiResult);
				if ($this->CallQuality === null)
				{
					$this->fillCallQuality();
				}
			}
			else
			{
				$this->trackError('Unexpected empty scoring result');
			}
		}
		elseif ($eventName === self::QUEUE_JOB_FAIL)
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

	private function scheduleScoringRetry(): void
	{
		$this->phase = self::PHASE_SCORING_RETRY;
		$retryHash = 'score_retry_' . md5(
			$this->workflow->getInstanceId()
			. ':' . $this->name
			. ':' . (int)$this->ActivityId
			. ':' . ($this->getAssessmentSettingsId() ?? 0),
		);
		$this->subscribeOnExternalEvents(
			self::MODULE_ID,
			self::LISTEN_EVENTS,
			$retryHash,
			self::SCORING_RETRY_DELAY_SECONDS,
		);
		$this->workflow->addEventHandler($this->name, $this);
	}

	private function handleSelectScriptEvent(string $eventName, array $arEventParameters): void
	{
		if ($eventName === self::QUEUE_JOB_FAIL)
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

		$activityId = (int)$this->ActivityId;
		$assessmentId = $this->extractAssessmentIdFromSelectScriptResult($arEventParameters);
		if ($assessmentId === null)
		{
			$this->trackError('Failed to determine assessment settings from AI');
			$this->unsubscribe($this);
			$this->workflow->cancelActivity($this);

			return;
		}

		$this->AssessmentSettingsId = [$assessmentId];
		$this->unsubscribe($this);

		$result = $this->executeScoring($activityId);
		if ($result === CBPActivityExecutionStatus::Closed)
		{
			$this->workflow->closeActivity($this);
		}
	}

	/**
	 * The AI result carried by the event is the primary source, because this activity is woken by the
	 * very ai:onQueueJobExecute event that CRM uses to persist the job result, and the handler order on
	 * that event is undefined. Reading the job row alone reports a not-yet-persisted PENDING row as a
	 * failed selection and cancels the whole workflow. The row stays as a fallback for a resume that
	 * carries no result object.
	 */
	private function extractAssessmentIdFromSelectScriptResult(array $arEventParameters = []): ?int
	{
		$aiResult = $this->extractEventObject($arEventParameters, \Bitrix\AI\Result::class);
		if ($aiResult instanceof \Bitrix\AI\Result)
		{
			$assessmentId = SelectCallScoreScript::extractScriptIdFromAIResult($aiResult);
			if ($assessmentId !== null)
			{
				return $assessmentId;
			}
		}

		if ($this->jobId === null)
		{
			return null;
		}

		$job = $this->loadJobById($this->jobId);
		if ($job === null)
		{
			return null;
		}

		$result = SelectCallScoreScript::constructResult($job);

		// PENDING carries neither payload nor errors, so isSuccess() is true for it: without this guard
		// it falls through to the payload read below and a timing problem is reported as a model failure.
		if ($result->isPending())
		{
			$this->trackError('Script selection job is still pending, its result cannot be read yet');

			return null;
		}

		if (!$result->isSuccess())
		{
			return null;
		}

		return $this->extractAssessmentIdFromPayload($result);
	}

	private function subscribeAndWaitForJob(int $jobId, int $typeId): string
	{
		$this->jobId = $jobId;

		$job = $this->loadJobById($jobId);
		if ($job === null)
		{
			if ($this->phase === self::PHASE_WAIT_SCORING_SLOT)
			{
				return $this->scheduleOwnScoringAfterSlotRelease();
			}

			return self::WAIT_OUTCOME_MISSING;
		}

		if ($job->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_PENDING)
		{
			if ($this->phase === self::PHASE_WAIT_SCORING_SLOT)
			{
				return $this->scheduleOwnScoringAfterSlotRelease();
			}

			return $this->processJobResult($job, $typeId);
		}

		$this->subscribeOnExternalEvents(
			self::MODULE_ID,
			self::LISTEN_EVENTS,
			$job->getHash(),
			self::TIMEOUT_SECONDS,
		);
		$this->workflow->addEventHandler($this->name, $this);

		// Re-check after subscribe to close the race window
		JobRepository::getInstance()->cleanRuntimeCache();

		$freshJob = $this->loadJobById($jobId);
		if ($freshJob === null)
		{
			$this->unsubscribe($this);
			if ($this->phase === self::PHASE_WAIT_SCORING_SLOT)
			{
				return $this->scheduleOwnScoringAfterSlotRelease();
			}

			return self::WAIT_OUTCOME_MISSING;
		}

		if ($freshJob->requireExecutionStatus() !== QueueTable::EXECUTION_STATUS_PENDING)
		{
			$this->unsubscribe($this);
			if ($this->phase === self::PHASE_WAIT_SCORING_SLOT)
			{
				return $this->scheduleOwnScoringAfterSlotRelease();
			}

			$outcome = $this->processJobResult($freshJob, $typeId);

			return $outcome;
		}

		return self::WAIT_OUTCOME_PENDING;
	}

	private function scheduleOwnScoringAfterSlotRelease(): string
	{
		$this->scheduleScoringRetry();

		return self::WAIT_OUTCOME_PENDING;
	}

	private function processJobResult(EO_Queue $job, int $typeId): string
	{
		if ($typeId === ScoreCallV2::TYPE_ID)
		{
			$result = ScoreCallV2::constructResult($job);
			if ($result->isPending())
			{
				return self::WAIT_OUTCOME_PENDING;
			}

			if ($result->isSuccess())
			{
				$this->fillCallQuality();

				return self::WAIT_OUTCOME_DONE_SUCCESS;
			}

			foreach ($result->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return self::WAIT_OUTCOME_DONE_FAIL;
		}

		if ($typeId === SelectCallScoreScript::TYPE_ID)
		{
			$result = SelectCallScoreScript::constructResult($job);
			if ($result->isPending())
			{
				return self::WAIT_OUTCOME_PENDING;
			}

			if ($result->isSuccess())
			{
				$assessmentId = $this->extractAssessmentIdFromPayload($result);
				if ($assessmentId === null)
				{
					$this->trackError('Failed to determine assessment settings from AI');

					return self::WAIT_OUTCOME_DONE_FAIL;
				}
				$this->AssessmentSettingsId = [$assessmentId];

				return self::WAIT_OUTCOME_DONE_SUCCESS;
			}

			foreach ($result->getErrors() as $error)
			{
				$this->trackError($error->getMessage());
			}

			return self::WAIT_OUTCOME_DONE_FAIL;
		}

		return self::WAIT_OUTCOME_PENDING;
	}

	private function loadJobById(int $jobId): ?EO_Queue
	{
		return QueueTable::query()
			->setSelect(['*'])
			->where('ID', $jobId)
			->fetchObject()
		;
	}

	private function fillCallQuality(): void
	{
		if ($this->getAssessmentSettingsId() === null || $this->jobId === null)
		{
			return;
		}

		$assessment = AiQualityAssessmentController::getInstance()
			->getByActivityIdAndJobId((int)$this->ActivityId, $this->jobId)
		;

		$this->CallQuality = isset($assessment['ASSESSMENT']) ? (int)$assessment['ASSESSMENT'] : null;
	}

	private function extractAssessmentIdFromPayload(\Bitrix\Crm\Integration\AI\Result $result): ?int
	{
		/** @var SelectCallScoringScriptPayload|null $payload */
		$payload = $result->getPayload();
		if ($payload === null)
		{
			return null;
		}

		$scriptId = $payload->scriptId;

		return empty($scriptId) ? null : $scriptId;
	}

	public static function getPropertiesMap(array $documentType, array $context = []): array
	{
		$options = self::getCallAssessmentList();

		return [
			'AssessmentSettingsId' => [
				'Name' => Loc::getMessage('CRM_GCAA_ITEM'),
				'FieldName' => 'AssessmentSettingsId',
				'Type' => FieldType::SELECT,
				'Multiple' => true,
				'Options' => $options,
				'Required' => false,
				'AllowSelection' => true,
			],
			'Transcription' => [
				'Name' => Loc::getMessage('CRM_GCAA_TRANSCRIPTION'),
				'FieldName' => 'Transcription',
				'Type' => FieldType::STRING,
				'Required' => true,
			],
			'ActivityId' => [
				'Name' => Loc::getMessage('CRM_GCAA_ACTIVITY_ID'),
				'FieldName' => 'ActivityId',
				'Type' => FieldType::INT,
				'Required' => true,
			],
			'UserId' => [
				'Name' => Loc::getMessage('CRM_GCAA_USER_ID'),
				'FieldName' => 'UserId',
				'Type' => FieldType::INT,
				'Required' => true,
			],
			'LowBorder' => [
				'Name' => Loc::getMessage('CRM_GCAA_LOW_BORDER'),
				'FieldName' => 'LowBorder',
				'Type' => FieldType::INT,
				'Required' => false,
			],
			'HighBorder' => [
				'Name' => Loc::getMessage('CRM_GCAA_HIGH_BORDER'),
				'FieldName' => 'HighBorder',
				'Type' => FieldType::INT,
				'Required' => false,
			],
		];
	}

	private static function getCallAssessmentList(): array
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
			$cache[(int)$item['ID']] = $item['TITLE'];
		}

		return $cache;
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

	public static function getPropertiesDialogValues(
		$documentType,
		$activityName,
		&$workflowTemplate,
		&$workflowParameters,
		&$workflowVariables,
		$currentValues,
		&$arErrors,
	): bool
	{
		$documentService = CBPRuntime::getRuntime()->getDocumentService();
		$map = static::getPropertiesMap($documentType);

		$properties = [];
		$errors = [];
		foreach ($map as $id => $property)
		{
			$value = $documentService->getFieldInputValue(
				$documentType,
				$property,
				$property['FieldName'],
				$currentValues,
				$errors,
			);

			if (!empty($errors))
			{
				return false;
			}

			$properties[$id] = $value;
		}

		$currentActivity = &CBPWorkflowTemplateLoader::FindActivityByName($workflowTemplate, $activityName);
		$currentActivity['Properties'] = $properties;

		return true;
	}
}
