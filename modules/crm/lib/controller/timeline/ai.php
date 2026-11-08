<?php

namespace Bitrix\Crm\Controller\Timeline;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario as BizprocScenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Crm\Activity\Mail\CopilotThreadCollector;
use Bitrix\Crm\Activity\Mail\Message;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Controller\Copilot\CallQualityAssessment;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Controller\Timeline\Trait\CopilotResponseLoader;
use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Copilot\AiQualityAssessment\ViewModeEnum;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Copilot\CallAssessment\PromptsChecker;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Entity\EntityEditorOptionBuilder;
use Bitrix\Crm\Entity\FieldDataProvider;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\FillItemFieldsFromCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\Dto\MultipleFieldFillPayload;
use Bitrix\Crm\Integration\AI\Dto\Scoring\ScoreCallV2Payload;
use Bitrix\Crm\Integration\AI\Dto\SingleFieldFillPayload;
use Bitrix\Crm\Integration\AI\ErrorCode as AIErrorCode;
use Bitrix\Crm\Integration\AI\Feedback;
use Bitrix\Crm\Integration\AI\Field\MultipleValueMerger;
use Bitrix\Crm\Integration\AI\JobRepository;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\StorageManager;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Context;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Service\Timeline\Config;
use Bitrix\Crm\Service\UserPermissions;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\ArgumentOutOfRangeException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\NotSupportedException;
use Bitrix\Main\Text\HtmlFilter;
use Bitrix\Main\UserField\Dispatcher;
use Bitrix\Main\Web\Json;
use CCrmActivity;
use CCrmActivityDirection;
use CCrmOwnerType;
use CUserOptions;

class AI extends Activity
{
	use CopilotResponseLoader;

	private const OPTION_NAME_NUMBER_OF_MANUAL_STARTS = 'timeline-copilot-button-in-call-manual-starts-v2';

	protected JobRepository $jobRepository;
	private UserPermissions $permissions;
	private Dispatcher $dispatcher;

	protected function init(): void
	{
		parent::init();

		$this->permissions = Container::getInstance()->getUserPermissions();
		$this->jobRepository = JobRepository::getInstance();
		$this->dispatcher = Dispatcher::instance();
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		$filters[] = new ActionFilter\Scope(ActionFilter\Scope::NOT_REST);
		$filters[] = new class extends ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (!AIManager::isAiCallProcessingEnabled())
				{
					$this->addError(ErrorCode::getAccessDeniedError());

					return new EventResult(EventResult::ERROR, null, 'crm', $this);
				}

				return null;
			}
		};

		return $filters;
	}

	/**
	 * @override
	 *
	 * @return array[]
	 */
	public function configureActions(): array
	{
		return [
			'mergeFields' => [
				'prefilters' => [
					new ActionFilter\HttpMethod(
						[
							ActionFilter\HttpMethod::METHOD_GET
						]
					),
					new ActionFilter\Csrf()
				]
			]
		];
	}

	// region AI and related sliders scenarios

	/**
	 * 'crm.timeline.ai.launchCopilot' method handler.
	 *
	 * @param int $activityId
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 * @param string|null $scenario
	 *
	 * @return int[]|null
	 *
	 * @noinspection PhpUnused
	 */
	public function launchCopilotAction(int $activityId, int $ownerTypeId, int $ownerId, ?string $scenario = null): ?array
	{
		if (empty($scenario))
		{
			$scenario = Scenario::FULL_SCENARIO;
		}

		if (!Scenario::isSupportedScenario($scenario))
		{
			return null;
		}

		$activity = $this->loadActivity($activityId, $ownerTypeId, $ownerId);
		if (!$activity)
		{
			return null;
		}

		if (!$this->isUpdateEnable($ownerTypeId, $ownerId))
		{
			return null;
		}

		if (
			AIManager::isAiCallProcessingEnabled()
			&& (
				// Non-fill scenarios (transcribe/summarize/scoring/analyze/full) stay limited to the
				// classic Deal/Lead types; the extended Factory-based target set is allowed only for
				// the fill-fields scenario. See fill-fields-any-entity.
				in_array($ownerTypeId, AIManager::SUPPORTED_ENTITY_TYPE_IDS, true)
				|| (
					$scenario === Scenario::FILL_FIELDS_SCENARIO
					&& AIManager::isEntityTypeSupported($ownerTypeId)
				)
			)
		)
		{
			$activityProvider = $activity['PROVIDER_ID'] ?? null;

			if (
				$scenario === Scenario::FULL_SCENARIO
				&& !Scenario::isManualFullScenarioAvailable($activityProvider)
			)
			{
				$this->addError(
					AIErrorCode::getAIDisabledError(['sliderCode' => Scenario::FULL_OFF_SLIDER_CODE])
				);

				return null;
			}

			$scenario = Scenario::filterFullScenarioByGlobalSettings($scenario);
			if (!Scenario::isEnabledScenario($scenario))
			{
				$sliderCode = Scenario::getDisabledSliderCode($scenario);
				if ($sliderCode !== null)
				{
					$this->addError(
						AIErrorCode::getAIDisabledError(['sliderCode' => $sliderCode])
					);
				}
				else
				{
					$this->addError(AIErrorCode::getAIScenarioError());
				}

				return null;
			}

			$fillFieldsEntityAllowed = CopilotSettings::getCurrent()->isFillFieldsEnabledFor($ownerTypeId);
			if (
				!AIManager::isFillFieldsEnabledForEntityType($ownerTypeId)
				&& $scenario === Scenario::FILL_FIELDS_SCENARIO
			)
			{
				AIManager::logger()->info(
					'{date}: Manual fill fields launch is disabled for entity type {entityTypeId}' . PHP_EOL,
					[
						'activityId' => $activityId,
						'entityTypeId' => $ownerTypeId,
						'scenario' => $scenario,
					],
				);
				$this->addError(AIErrorCode::getFillFieldsEntityDisabledError($ownerTypeId));

				return null;
			}

			if (Scenario::isCallScoringScenario($scenario))
			{
				$checkerResult = CallAssessmentItemChecker::getInstance()
					->setItem(ItemFactory::getByActivityId($activityId))
					->run()
				;
				if (!$checkerResult->isSuccess())
				{
					$this->addError($checkerResult->getError());

					return null;
				}

				if (AIManager::isCallScoringV2Enabled())
				{
					// Idempotent manual launch: if a scoring pipeline is already running for this
					// activity, a repeated click must not relaunch steps or start a second workflow.
					// Return a soft success so the client keeps showing the in-progress state.
					if ($this->isCallScoringV2PipelineInProgress($activityId))
					{
						return [
							'numberOfManualStarts' => $this->processNumberOfManualStarts(),
						];
					}

					$preflightErrors = $this->preflightCallScoringV2Limits($activityId);
					if ($preflightErrors !== null)
					{
						$this->addErrors($preflightErrors);

						return null;
					}

					$triggerResult = $this->fireCallAssessmentTrigger($activityId);
					if ($triggerResult === null || !$triggerResult->isSuccess())
					{
						$this->addErrors($triggerResult?->getErrors() ?? []);
						$this->addError(AIErrorCode::getAIEngineNotFoundError());

						return null;
					}

					return [
						'numberOfManualStarts' => $this->processNumberOfManualStarts(),
					];
				}
			}

			$executor = ServiceLocator::getInstance()->get(PipelineExecutor::class);
			$anchorActivityId = $activityId;
			if ($activityProvider === Email::getId())
			{
				$threadId = (int)($activity['THREAD_ID'] ?? 0);
				if ($threadId > 0)
				{
					$rootRow = CopilotThreadCollector::findEntityLocalRoot($threadId, $ownerTypeId, $ownerId);
					if ($rootRow !== null)
					{
						$anchorActivityId = (int)$rootRow['ID'];
					}
				}
			}

			// Manual launch fills exactly the clicked entity (ownerTypeId/ownerId) from the timeline
			// context; the pipeline must not re-resolve the target to a priority Deal/Lead (ALG-01).
			$context = new StepContext(
				activityId: $anchorActivityId,
				userId: Container::getInstance()->getContext()->getUserId(),
				scenarioName: $scenario,
				isManualLaunch: true,
				manualTarget: ItemIdentifier::createByParams($ownerTypeId, $ownerId),
			);
			$result = $executor->startOrResume(
				$context
					->withActivityProvider($activityProvider)
					->withExtra('targetOwnerTypeId', $ownerTypeId)
					->withExtra('targetOwnerId', $ownerId)
					->withExtra('fillFieldsEntityAllowed', $fillFieldsEntityAllowed)
			);
			if (!$result?->isSuccess())
			{
				$errors = $result?->getErrors();
				if ($errors)
				{
					$this->addErrors($result?->getErrors());
				}
				else
				{
					$this->addError(AIErrorCode::getAIEngineNotFoundError());
				}

				return null;
			}

			return [
				'numberOfManualStarts' => $this->processNumberOfManualStarts(),
			];
		}

		$this->addError(AIErrorCode::getAIEngineNotFoundError());

		return null;
	}

	/**
	 * 'crm.timeline.ai.getCopilotTranscript' method handler.
	 *
	 * @param int $activityId
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 *
	 * @return array|null
	 */
	public function getCopilotTranscriptAction(int $activityId, int $ownerTypeId, int $ownerId): ?array
	{
		$activity = $this->loadActivity($activityId, $ownerTypeId, $ownerId);
		if (!$activity)
		{
			return null;
		}

		$transcription = $this->loadTranscript($activityId);
		if (!$transcription)
		{
			return null;
		}

		$callRecord = $this->getCallRecord($activityId, $ownerTypeId, $ownerId);
		if (!$callRecord)
		{
			return null;
		}

		$this->removeEntityBadgeByOwner(new ItemIdentifier($ownerTypeId, $ownerId));

		return [
			'aiJobResult' => $transcription,
			'callRecord' => $callRecord,
		];
	}

	/**
	 * 'crm.timeline.ai.getCopilotSummary' method handler.
	 *
	 * @param int $activityId
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 * @param int|null $jobId
	 *
	 * @return array|null
	 */
	public function getCopilotSummaryAction(int $activityId, int $ownerTypeId, int $ownerId, ?int $jobId = null): ?array
	{
		$activity = $this->loadActivity($activityId, $ownerTypeId, $ownerId);
		if (!$activity)
		{
			return null;
		}

		$resolvedActivityId = $activityId;
		$isEmail = $activity['PROVIDER_ID'] === Email::getId();
		if ($isEmail)
		{
			$threadId = (int)($activity['THREAD_ID'] ?? 0);
			if ($threadId > 0)
			{
				$rootRow = CopilotThreadCollector::findEntityLocalRoot($threadId, $ownerTypeId, $ownerId);
				if ($rootRow !== null)
				{
					$resolvedActivityId = (int)$rootRow['ID'];
				}
			}
		}

		$summary = $isEmail
			? $this->loadSummary($resolvedActivityId, $jobId, $ownerTypeId, $ownerId)
			: $this->loadSummary($resolvedActivityId, $jobId)
		;
		if (!$summary)
		{
			return null;
		}

		if ($isEmail && isset($summary['summary']))
		{
			$summary['summary'] = HtmlFilter::encode($summary['summary']);
		}

		if ($activity['PROVIDER_ID'] === OpenLine::getId())
		{
			$communications = CCrmActivity::PrepareCommunicationInfos([$activityId]);
			$userCode = $activity['PROVIDER_PARAMS']['USER_CODE'] ?? '';

			$result = [
				'aiJobResult' => $summary,
				'openline' => [
					'name' => OpenLine::getChatName($userCode),
					'dialogId' => $communications[$activityId]['VALUE'] ?? null,
				],
			];
		}
		elseif ($activity['PROVIDER_ID'] === Email::getId())
		{
			$senderName = '';
			$rootActivity = $resolvedActivityId === $activityId
				? $activity
				: Container::getInstance()->getActivityBroker()->getById($resolvedActivityId);
			if ($rootActivity)
			{
				$from = $rootActivity['SETTINGS']['EMAIL_META']['from'] ?? null;
				if ($from)
				{
					$senderName = (new \Bitrix\Main\Mail\Address($from))->getName();
				}
			}

			$result = [
				'aiJobResult' => $summary,
				'emailThread' => [
					'activityId' => $resolvedActivityId,
					'subject' => Message::getSubjectById($resolvedActivityId),
					'senderName' => $senderName,
				],
			];
		}
		else
		{
			$callRecord = $this->getCallRecord($activityId, $ownerTypeId, $ownerId);
			if (!$callRecord)
			{
				return null;
			}

			$result = [
				'aiJobResult' => $summary,
				'callRecord' => $callRecord,
			];
		}

		$this->removeEntityBadgeByOwner(new ItemIdentifier($ownerTypeId, $ownerId));

		return $result;
	}

	/**
	 * 'crm.timeline.ai.getCopilotCallQuality' method handler.
	 *
	 * @param int $activityId
	 * @param int $ownerTypeId
	 * @param int $ownerId
	 * @param int|null $jobId
	 * @param int|null $assessmentSettingsId
	 *
	 * @return array|null
	 */
	public function getCopilotCallQualityAction(int $activityId, int $ownerTypeId, int $ownerId, ?int $jobId = null, ?int $assessmentSettingsId = null): ?array
	{
		$activity = $this->loadActivity($activityId, $ownerTypeId, $ownerId);
		if (!$activity)
		{
			return null;
		}

		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId)
		{
			$pullManager = new PullManager();
			$pullManager->subscribe($userId, $activityId);
		}

		$data = [
			'callRecord' => [],
			'callDirection' => $activity['DIRECTION'] ?? CCrmActivityDirection::Undefined,
		];

		$callRecord = $this->getCallRecord($activityId, $ownerTypeId, $ownerId);
		if ($callRecord === null)
		{
			$data['viewMode'] = ViewModeEnum::error->value;

			return $data;
		}

		$data['callRecord'] = $callRecord;

		$callQuality = $this->getCopilotCallQualityData($activityId, $jobId);
		if ($callQuality === null)
		{
			if ($assessmentSettingsId)
			{
				$callQualityAssessmentController = new CallQualityAssessment();
				$callQuality = $callQualityAssessmentController->getAction($activityId, $assessmentSettingsId, new ItemIdentifier($ownerTypeId, $ownerId));

				return array_merge($data, $callQuality);
			}

			$data['viewMode'] = ViewModeEnum::emptyScriptList->value;

			return $data;
		}

		$data['callQuality'] = $callQuality;

		$this->prepareDataWithCallScoring($data, $activityId, $jobId);

		return $data;
	}

	/**
	 * 'crm.timeline.ai.fieldsFillingStatus' method handler.
	 *
	 * @param int $mergeId
	 *
	 * @return array
	 */
	public function fieldsFillingStatusAction(int $mergeId): array
	{
		$operation = $this->jobRepository->getFieldsFillingOperationById($mergeId);
		if ($operation)
		{
			$this->removeEntityBadgeByOwner(new ItemIdentifier($operation->getEntityTypeId(), $operation->getEntityId()));
		}

		return [
			'operationStatus' => $operation?->getOperationStatus(),
		];
	}

	/**
	 * 'crm.timeline.ai.mergeFields' method handler.
	 *
	 * @param int $mergeUuid
	 *
	 * @return array|null
	 *
	 * @throws ArgumentException
	 * @throws NotSupportedException
	 *
	 * @noinspection PhpUnused
	 */
	public function mergeFieldsAction(int $mergeUuid): ?array
	{
		$result = $this->getAndCheckFillFieldsResult($mergeUuid);
		if (!$result)
		{
			return null;
		}

		$factory = Container::getInstance()->getFactory($result->getTarget()?->getEntityTypeId());
		if (!$factory || !CCrmOwnerType::isUseFactoryBasedApproach($factory->getEntityTypeId()))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($result->getTarget()?->getEntityTypeId()));

			return null;
		}

		$item = $factory->getItem($result->getTarget()?->getEntityId());
		if (!$item)
		{
			$this->addError(ErrorCode::getOwnerNotFoundError());

			return null;
		}

		if (!$this->permissions->item()->canUpdateItem($item))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		if ($result->isInFinalOperationStatus())
		{
			$this->addError(AIErrorCode::getOperationIsCompleteError());

			return null;
		}

		// actualize conflicts info
		FillItemFieldsFromCallTranscription::calculateConflicts(
			$result->getPayload(),
			$factory,
			$item,
		);

		$feedbackResult = $this->jobRepository->getFillItemFieldsFromCallTranscriptionResultById($mergeUuid);

		return [
			'fields' => $this->prepareMergeFields($result->getPayload(), $factory, $item),
			'target' =>
				[
					'entityTypeName' => $factory->getEntityName(),
					'editorId' => $this->makeEditorId(
						$result->getTarget()?->getEntityTypeId(),
						$item->isCategoriesSupported() ? $item->getCategoryId() : null,
					),
					'feedbackWasSent' => Feedback::wasSent($feedbackResult),
				]
				+ $result->getTarget()?->jsonSerialize()
			,
			'editMode' => true,
		];
	}

	/**
	 * 'crm.timeline.ai.rejectMerge' method handler.
	 *
	 * @param int $mergeUuid
	 *
	 * @throws ArgumentOutOfRangeException
	 *
	 * @noinspection PhpUnused
	 */
	public function rejectMergeAction(int $mergeUuid): void
	{
		$result = $this->getAndCheckFillFieldsResult($mergeUuid);
		if (!$result)
		{
			return;
		}

		if ($result->isInFinalOperationStatus())
		{
			$this->addError(AIErrorCode::getOperationIsCompleteError());

			return;
		}

		$result->setOperationStatus(Result::OPERATION_STATUS_REJECTED);

		$updateResult = $this->jobRepository->updateFillItemFieldsFromCallTranscriptionResult($result);

		if ($updateResult->isSuccess())
		{
			FillItemFieldsFromCallTranscription::onAfterConflictReject($result);
		}
		else
		{
			$this->addErrors($updateResult->getErrors());
		}
	}

	/**
	 * 'crm.timeline.ai.applyMerge' method handler.
	 *
	 * @param int $mergeUuid
	 * @param array $fieldNamesToApply
	 *
	 * @throws ArgumentException
	 *
	 * @noinspection PhpUnused
	 */
	public function applyMergeAction(int $mergeUuid, array $fieldNamesToApply): void
	{
		$result = $this->getAndCheckFillFieldsResult($mergeUuid);
		if (!$result)
		{
			return;
		}

		if ($result->isInFinalOperationStatus())
		{
			$this->addError(AIErrorCode::getOperationIsCompleteError());

			return;
		}

		$factory = Container::getInstance()->getFactory($result->getTarget()?->getEntityTypeId());
		if (!$factory || !CCrmOwnerType::isUseFactoryBasedApproach($factory->getEntityTypeId()))
		{
			$this->addError(ErrorCode::getEntityTypeNotSupportedError($result->getTarget()?->getEntityTypeId()));

			return;
		}

		$item = $factory->getItem($result->getTarget()?->getEntityId());
		if (!$item)
		{
			$this->addError(ErrorCode::getOwnerNotFoundError());

			return;
		}

		if (!$this->permissions->item()->canUpdateItem($item))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return;
		}

		//todo move to operation?
		$categoryId = $item->isCategoriesSupported() ? $item->getCategoryId() : null;
		$whitelist = (new FieldDataProvider($factory->getEntityTypeId(), Context::SCOPE_AI))
			->getDisplayedInEntityEditorFieldData($this->getCurrentUser()?->getId(), $categoryId)
		;

		$payload = $result->getPayload();
		foreach ($payload?->singleFields as $singleField)
		{
			if (
				isset($whitelist[$singleField->name])
				&& in_array($singleField->name, $fieldNamesToApply, true)
				&& !$singleField->isApplied
				&& $item->hasField($singleField->name)
			)
			{
				$item->set($singleField->name, $singleField->aiValue);
				$singleField->isApplied = true;
			}
		}

		$multipleValueMerger = new MultipleValueMerger();
		foreach ($payload?->multipleFields as $multipleField)
		{
			if (
				isset($whitelist[$multipleField->name])
				&& in_array($multipleField->name, $fieldNamesToApply, true)
				&& !$multipleField->isApplied
				&& $item->hasField($multipleField->name)
			)
			{
				$field = $factory->getFieldsCollection()->getField($multipleField->name);
				if ($field === null)
				{
					continue;
				}

				$previousValue = $item->get($multipleField->name);
				$previousValues = $multipleValueMerger->merge(
					$field,
					$previousValue,
					[],
				);
				$newValue = $multipleValueMerger->merge(
					$field,
					$previousValue,
					$multipleField->aiValues,
				);
				if (count($newValue) > count($previousValues))
				{
					$item->set($multipleField->name, $newValue);
				}

				$multipleField->isApplied = true;
			}
		}

		$context = (new Context())
			->setUserId($this->getCurrentUser()?->getId())
			->setScope(Context::SCOPE_AI)
		;

		$operation = $factory->getUpdateOperation($item, $context)
			// disable all checks except check access
			->disableAllChecks()
			->enableCheckAccess()
			->disableBizProc()
			->disableAutomation()
		;

		$updateResult = $operation->launch();
		if (!$updateResult->isSuccess())
		{
			$this->addErrors($updateResult->getErrors());

			return;
		}

		$result->setOperationStatus(Result::OPERATION_STATUS_APPLIED);

		$saveResult = $this->jobRepository->updateFillItemFieldsFromCallTranscriptionResult($result);
		if ($saveResult->isSuccess())
		{
			FillItemFieldsFromCallTranscription::onAfterConflictApply($result);
		}
		else
		{
			$this->addErrors($saveResult->getErrors());
		}
	}
	// endregion

	// region Send feedback scenarios
	/** @noinspection PhpUnused */
	public function sendFeedbackAction(int $mergeUuid): void
	{
		$result = $this->getAndCheckFillFieldsResult($mergeUuid);
		if (!$result)
		{
			return;
		}

		$consentResult = Feedback::grantConsent($result);
		if (!$consentResult->isSuccess())
		{
			$this->addErrors($consentResult->getErrors());

			return;
		}

		$enqueueResult = Feedback::addToSendQueue($result);
		if (!$enqueueResult->isSuccess())
		{
			$this->addErrors($enqueueResult->getErrors());
		}
	}

	/** @noinspection PhpUnused */
	public function wasFeedbackSentAction(int $mergeUuid): ?bool
	{
		$result = $this->getAndCheckFillFieldsResult($mergeUuid);
		if (!$result)
		{
			return null;
		}

		return Feedback::wasSent($result);
	}
	// endregion

	/**
	 * @param int $mergeUuid
	 *
	 * @return Result<FillItemFieldsFromCallTranscriptionPayload>|null
	 */
	private function getAndCheckFillFieldsResult(int $mergeUuid): ?Result
	{
		$result = $this->jobRepository->getFillItemFieldsFromCallTranscriptionResultById($mergeUuid);
		if (!$result)
		{
			$this->addError(ErrorCode::getNotFoundError());

			return null;
		}

		if ($result->isPending() || !$result->isSuccess())
		{
			$this->addError(new Error('Only successful finished jobs are allowed', AIErrorCode::JOB_IN_WRONG_STATUS));

			return null;
		}

		if (
			!$result->getTarget()
			|| !$this->permissions->item()->canUpdateItemIdentifier($result->getTarget())
		)
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		return $result;
	}

	private function processNumberOfManualStarts(): int
	{
		$numberOfManualStarts = (int)CUserOptions::getOption(
			'crm',
			self::OPTION_NAME_NUMBER_OF_MANUAL_STARTS,
			0
		);

		$newNumberOfManualStarts = $numberOfManualStarts + 1;

		CUserOptions::setOption(
			'crm',
			self::OPTION_NAME_NUMBER_OF_MANUAL_STARTS,
			$newNumberOfManualStarts
		);

		return $newNumberOfManualStarts;
	}

	/**
	 * Detects whether a call scoring V2 pipeline is already in flight for the activity:
	 * any pending transcription, script selection or scoring job means a run is in progress
	 * (started by an earlier click / workflow), so a new manual launch must be a no-op.
	 */
	private function isCallScoringV2PipelineInProgress(int $activityId): bool
	{
		$pipelineTypeIds = [
			TranscribeCallRecording::TYPE_ID,
			SelectCallScoreScript::TYPE_ID,
			ScoreCallV2::TYPE_ID,
		];

		foreach ($pipelineTypeIds as $typeId)
		{
			if ($this->jobRepository->getPendingJobByActivity($activityId, $typeId) !== null)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Synchronously launches the SelectCallScoreScript step so that AI request-limit errors
	 * (which carry sliderCode in customData) reach the client and trigger the limit slider —
	 * the same UX as FILL_FIELDS_SCENARIO has via PipelineExecutor. The bizproc activity
	 * CBPCrmGetCallAssessmentActivity reuses the pending job we create here.
	 *
	 * Returns the errors to surface, or null if no pre-flight action was needed / it succeeded.
	 */
	private function preflightCallScoringV2Limits(int $activityId): ?array
	{
		$transcriptionResult = $this->jobRepository->getTranscribeCallRecordingResultByActivity($activityId);

		if ($transcriptionResult === null)
		{
			$userId = Container::getInstance()->getContext()->getUserId();
			$launchResult = AIManager::launchCallRecordingTranscription(
				$activityId,
				Scenario::resolveCallScoringScenarioName(),
				$userId,
				isManualLaunch: true,
			);

			return $launchResult->isSuccess()
				? null
				: $this->filterOutDuplicateJobErrors($launchResult->getErrors())
			;
		}

		if (!$transcriptionResult->isSuccess())
		{
			return null;
		}

		if ($this->jobRepository->getPendingJobByActivity($activityId, SelectCallScoreScript::TYPE_ID) !== null)
		{
			return null;
		}

		$transcription = (string)($transcriptionResult->getPayload()?->transcription ?? '');
		if ($transcription === '')
		{
			return null;
		}

		$launchResult = AIManager::launchSelectCallScoringScript(
			$activityId,
			$transcription,
			Container::getInstance()->getContext()->getUserId(),
			isManualLaunch: true,
		);

		return $launchResult->isSuccess()
			? null
			: $this->filterOutDuplicateJobErrors($launchResult->getErrors())
		;
	}

	/**
	 * A duplicate-job error means the step is already running or already finished — a normal state for
	 * a repeated click, not a launch failure. It must not abort the pre-flight: the caller returns
	 * before firing the call assessment trigger, so the button would stay a no-op for as long as the
	 * finished job row exists. The workflow activity re-runs the step on its own
	 * (CBPCrmGetCallAssessmentActivity::launchSelectScript drops the previous job first).
	 */
	private function filterOutDuplicateJobErrors(array $errors): ?array
	{
		$blockingErrors = array_values(
			array_filter(
				$errors,
				static fn(Error $error) => $error->getCode() !== AIErrorCode::JOB_ALREADY_EXISTS,
			),
		);

		return $blockingErrors ?: null;
	}

	/**
	 * Manual scenario launch from the timeline does not pick a specific assessment script —
	 * we pass AssessmentSettingsId=0 and let the bizproc activity (SelectCallScoreScript) auto-select.
	 */
	private function fireCallAssessmentTrigger(int $activityId): ?\Bitrix\Main\Result
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		return Starter::getByScenario(BizprocScenario::onEvent)
			->setContext(new ContextDto('crm'))
			->addEvent('CrmCallAssessmentTrigger', [], [
				'ActivityId' => $activityId,
				'AssessmentSettingsId' => 0,
				'UserId' => Container::getInstance()->getContext()->getUserId(),
			])
			->start()
		;
	}

	protected function removeEntityBadgeByOwner(ItemIdentifier $identifier): void
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		$assignedById = Container::getInstance()
			->getFactory($identifier->getEntityTypeId())
			?->getItem($identifier->getEntityId())?->getAssignedById()
		;
		if ($currentUserId === $assignedById)
		{
			Badge::deleteByEntity($identifier, Badge::AI_FIELDS_FILLING_RESULT);
		}
	}

	private function makeEditorId(int $entityTypeId, ?int $categoryId = null): string
	{
		// Universal card-editor config id for any Factory-based entity, including smart processes.
		// For Deal/Lead this yields the same ids as before ('deal_details'/'lead_details',
		// with the '_c_{categoryId}' suffix for categorized deals) — full parity.
		return (new EntityEditorOptionBuilder($entityTypeId))
			->setCategoryId($categoryId)
			->build()
		;
	}

	private function getCallRecord(int $activityId, int $ownerTypeId, int $ownerId): ?array
	{
		$activity = $this->loadActivity($activityId, $ownerTypeId, $ownerId);
		if (!$activity)
		{
			return null;
		}

		$elementIds = unserialize((string)($activity['STORAGE_ELEMENT_IDS'] ?? ''), ['allowed_classes' => false]);
		if (
			!is_array($elementIds)
			|| empty($elementIds)
			|| !isset($elementIds[0])
			|| (int)$elementIds[0] <= 0
		)
		{
			$this->addError(new Error('Call record not found'));

			return null;
		}

		$storageTypeId = $activity['STORAGE_TYPE_ID'];
		try
		{
			// pick first call
			$fileInfo = StorageManager::getFileInfo(
				(int)$elementIds[0],
				$storageTypeId,
				true,
				[
					'OWNER_ID' => (int)$activity['ID'],
					'OWNER_TYPE_ID' => CCrmOwnerType::Activity,
				]
			);
		}
		catch (NotSupportedException $exception)
		{
			$this->addError(new Error($exception->getMessage()));

			return null;
		}

		if (
			!is_array($fileInfo)
			|| empty($fileInfo)
			|| !in_array(GetFileExtension(mb_strtolower($fileInfo['NAME'])), Config::ALLOWED_AUDIO_EXTENSIONS, true)
		)
		{
			$this->addError(new Error('Call record not found'));

			return null;
		}

		return [
			'src' => $fileInfo['VIEW_URL'],
			'id' => mb_substr($activity['ORIGIN_ID'], 3),
			'title' => CCrmOwnerType::GetCaption($ownerTypeId, $ownerId),
		];
	}

	private function getCopilotCallQualityData(int $activityId, ?int $jobId): ?array
	{
		$aiQualityAssessmentController = AiQualityAssessmentController::getInstance();
		$callQuality = $aiQualityAssessmentController->getByActivityIdAndJobId($activityId, $jobId);
		if ($callQuality === null)
		{
			return null;
		}

		$callQuality['PREV_ASSESSMENT_AVG'] = $aiQualityAssessmentController
			->getPrevAvgAssessmentValue($callQuality['RATED_USER_ID'] ?? 0)
		;
		$callQuality['USE_IN_RATING'] = ($callQuality['USE_IN_RATING'] ?? 'N') === 'Y';
		$callQuality['IS_PROMPT_CHANGED'] = PromptsChecker::isChanged(
			$callQuality['PROMPT'],
			$callQuality['ACTUAL_PROMPT'],
		);

		$callQuality['RECOMMENDATIONS'] = '';
		$callQuality['SUMMARY'] = '';

		return $callQuality;
	}

	private function prepareDataWithCallScoring(array &$data, int $activityId, ?int $jobId): void
	{
		$callScoringResult = $this->jobRepository->getCallScoringResult($activityId, $jobId);

		if ($callScoringResult === null || !$callScoringResult->isSuccess())
		{
			$data['viewMode'] = ViewModeEnum::error->value;

			return;
		}

		if ($callScoringResult->isPending())
		{
			$data['viewMode'] = ViewModeEnum::pending->value;

			return;
		}

		/** @var ScoreCallV2Payload $payload */
		$payload = $callScoringResult->getPayload();
		$data['callQuality']['RECOMMENDATIONS'] = $payload?->recommendations;

		if (AIManager::isCallScoringV2Enabled())
		{
			$data['callQuality']['SUMMARY'] = empty($payload?->criteriaScores) ? null : $payload->criteriaScores;
		}
		else
		{
			$data['callQuality']['SUMMARY'] = !empty($payload?->criteriaScores)
				? Json::encode($payload->criteriaScores)
				: null
			;
		}

		if (empty($data['callQuality']['RECOMMENDATIONS']))
		{
			$data['viewMode'] = ViewModeEnum::error->value;
		}
		else
		{
			$data['viewMode'] = ViewModeEnum::usedCurrentVersionOfScript->value;
		}
	}

	private function prepareMergeFields(
		FillItemFieldsFromCallTranscriptionPayload $payload,
		Factory $factory,
		\Bitrix\Crm\Item $item
	): array
	{
		$json = [];
		$multipleValueMerger = new MultipleValueMerger();

		//todo move to operation?
		$categoryId = $item->isCategoriesSupported() ? $item->getCategoryId() : null;
		$whitelist = (new FieldDataProvider($factory->getEntityTypeId(), Context::SCOPE_AI))
			->getDisplayedInEntityEditorFieldData($this->getCurrentUser()?->getId(), $categoryId)
		;

		foreach (array_merge($payload->singleFields, $payload->multipleFields) as $dtoField)
		{
			/** @var SingleFieldFillPayload|MultipleFieldFillPayload $dtoField */

			$field = $factory->getFieldsCollection()->getField($dtoField->name);
			if (
				!$field
				|| !$item->hasField($dtoField->name)
				|| !isset($whitelist[$dtoField->name]) // return only fields that are displayed in entity details
				|| $dtoField->isApplied // return only fields that were not automatically applied to item
			)
			{
				continue;
			}

			if ($dtoField instanceof SingleFieldFillPayload)
			{
				$newValue = $dtoField->aiValue;
			}
			elseif ($dtoField instanceof MultipleFieldFillPayload)
			{
				$currentValue = $item->get($dtoField->name);
				$currentValues = $multipleValueMerger->merge($field, $currentValue, []);
				$newValue = $multipleValueMerger->merge(
					$field,
					$currentValue,
					$dtoField->aiValues,
				);
				if (count($newValue) === count($currentValues))
				{
					continue;
				}
			}
			else
			{
				throw new NotSupportedException('Unknown payload field type');
			}

			$json[] = [
				'name' => $field->getName(),
				'title' => $field->getTitle(),
				'isMultiple' => $field->isMultiple(),
				'type' => $field->getType(),
				'isUserField' => $field->isUserField(),
				'aiModel' => [
					'IS_EMPTY' => $field->isValueEmpty($newValue),
					'SIGNATURE' => $this->dispatcher->getSignature([
						'ENTITY_ID' => $factory->getUserFieldEntityId(),
						'FIELD' => $field->getName(),
						'VALUE' => $newValue,
					]),
					'VALUE' => $newValue,
				],
			];
		}

		return $json;
	}
}
