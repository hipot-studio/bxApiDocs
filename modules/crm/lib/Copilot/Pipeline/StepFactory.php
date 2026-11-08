<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScenarioOverrideResolver;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScoreCallSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use CCrmActivity;
use CCrmOwnerType;

final readonly class StepFactory
{
	private const SCREENING_ALLOWED_ENTITY_TYPES = [
		CCrmOwnerType::Deal => true,
	];

	public function __construct(private StepResultResolver $resultResolver, private TargetResolver $targetResolver) {}

	public function create(string $operationClass, StepContext $context): ?AbstractOperation
	{
		return match ($operationClass)
		{
			TranscribeCallRecording::class => $this->createTranscribe($context),
			SummarizeCallTranscription::class => $this->createSummarize($context),
			FillItemFieldsFromCallTranscription::class => $this->createFillFields($context),
			ScoreCall::class => $this->createScoreCall($context),
			ScoreCallV2::class => $this->createScoreCallV2($context),
			AnalyzeCommunication::class => $this->createAnalyzeCommunication($context),
			ExtractScoringCriteria::class => $this->createExtractScoringCriteria($context),
			FillRepeatSaleTips::class => $this->createFillRepeatSaleTips($context),
			ScreeningRepeatSaleItem::class => $this->createScreeningRepeatSaleItem($context),
			default => null,
		};
	}
	// region Private create methods

	/**
	 * Mirrors AIManager::launchCallRecordingTranscription logic:
	 * reads storageTypeId and storageElementId from the activity when not provided via context extra.
	 */
	private function createTranscribe(StepContext $context): ?AbstractOperation
	{
		$activityId = $context->getActivityId();

		$storageTypeId = $context->getExtra('storageTypeId');
		$storageElementId = $context->getExtra('storageElementId');

		if (!StorageType::isDefined($storageTypeId) || (int)$storageElementId <= 0)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
			if (!is_array($activity))
			{
				AIManager::logger()->warning(
					'{date}: {class}: activity with ID {activityId} not found' . PHP_EOL,
					[
						'class' => self::class,
						'activityId' => $activityId,
					],
				);

				return null;
			}

			$storageTypeId = $activity['STORAGE_TYPE_ID'] ?? null;

			$storageElementIds = CCrmActivity::extractStorageElementIds($activity) ?? [];
			if (!empty($storageElementIds))
			{
				$storageElementId = max($storageElementIds);
			}
		}

		if (!StorageType::isDefined($storageTypeId) || (int)$storageElementId <= 0)
		{
			return null;
		}

		return new TranscribeCallRecording(
			new ItemIdentifier(CCrmOwnerType::Activity, $activityId),
			(int)$storageTypeId,
			(int)$storageElementId,
			$context->getUserId(),
		);
	}

	/**
	 * Gets transcription from TranscribeCallRecording result.
	 * For OpenLine provider, gets chat messages via OpenLine::getMessagesForCopilot() instead of transcription.
	 * In skip-transcription mode (OpenLine provider), gets chat messages via OpenLine::getMessagesForCopilot().
	 * Returns null if Summarize is disabled — the step is gated by its own setting, and dependent steps
	 * (FillItemFields) are gated at the scenario level via FillFieldsScenario::isEnabled().
	 * PipelineExecutor will skip to the next step (e.g., ScoreCall).
	 */
	private function createSummarize(StepContext $context): ?AbstractOperation
	{
		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize))
		{
			return null;
		}

		if (
			!$this->shouldAutostartOperation($context, SummarizeCallTranscription::TYPE_ID)
			&& !$this->shouldAutostartOperation($context, FillItemFieldsFromCallTranscription::TYPE_ID)
		)
		{
			return null;
		}

		$activityId = $context->getActivityId();
		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);

		$isOpenLine = $context->getActivityProvider() === OpenLine::getId();
		$isEmail = $context->getActivityProvider() === Email::getId();

		if ($isOpenLine)
		{
			$messages = OpenLine::getMessagesForCopilot($activityId);
			if (!OpenLine::isCopilotProcessingAvailable($activityId, $messages, true, $this->resolveChatBaselineTarget($context)))
			{
				return null;
			}

			return new SummarizeCallTranscription(
				$target,
				$messages,
				$context->getUserId(),
			);
		}

		if ($isEmail)
		{
			$targetOwnerTypeId = (int)$context->getExtra('targetOwnerTypeId');
			$targetOwnerId = (int)$context->getExtra('targetOwnerId');
			$messages = Email::getMessagesForCopilot(
				$activityId,
				$targetOwnerTypeId,
				$targetOwnerId,
			);
			if (empty($messages))
			{
				return null;
			}

			return (new SummarizeCallTranscription(
				$target,
				$messages,
				$context->getUserId(),
			))
				->setContextExtra('targetOwnerTypeId', $targetOwnerTypeId)
				->setContextExtra('targetOwnerId', $targetOwnerId)
			;
		}

		$transcriptionResult = $this->resultResolver->resolve(TranscribeCallRecording::class, $context);
		$transcription = (string)($transcriptionResult?->getPayload()?->transcription ?? '');
		if (empty($transcription))
		{
			return null;
		}

		return new SummarizeCallTranscription(
			$target,
			$transcription,
			$context->getUserId(),
			$transcriptionResult->getJobId(),
		);
	}

	/**
	 * Manual launch: fills exactly the clicked entity carried in the context (no re-resolve).
	 * Auto path: uses TargetResolver for the priority Deal/Lead target (unchanged).
	 * Gets summary from SummarizeCallTranscription result.
	 * Returns null if FillItemFromCall is disabled or autostart settings don't allow it.
	 * PipelineExecutor will skip this step and proceed to the next one (e.g., ScoreCall).
	 */
	private function createFillFields(StepContext $context): ?AbstractOperation
	{
		if ($context->getExtra('fillFieldsEntityAllowed') === false)
		{
			return null;
		}

		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall))
		{
			return null;
		}

		$isEmail = $context->getActivityProvider() === Email::getId();
		$targetOwnerTypeId = (int)$context->getExtra('targetOwnerTypeId');
		$targetOwnerId = (int)$context->getExtra('targetOwnerId');

		if ($isEmail && ($targetOwnerTypeId <= 0 || $targetOwnerId <= 0))
		{
			$fallback = $this->resolveFillFieldsTarget($context);
			if ($fallback)
			{
				$targetOwnerTypeId = $fallback->getEntityTypeId();
				$targetOwnerId = $fallback->getEntityId();
			}
		}

		if ($isEmail && $targetOwnerTypeId > 0 && $targetOwnerId > 0)
		{
			$fillTarget = new ItemIdentifier($targetOwnerTypeId, $targetOwnerId);
		}
		else
		{
			$fillTarget = $this->resolveFillFieldsTarget($context);
		}

		if (!$fillTarget)
		{
			return null;
		}

		if (!$this->shouldAutostartOperation($context, FillItemFieldsFromCallTranscription::TYPE_ID, $fillTarget))
		{
			return null;
		}

		$summarizeResult = $this->resultResolver->resolve(SummarizeCallTranscription::class, $context);
		$summary = (string)($summarizeResult?->getPayload()?->summary ?? '');
		if (empty($summary))
		{
			return null;
		}

		$parentJobId = $summarizeResult->getJobId();
		if (!$parentJobId)
		{
			return null;
		}

		$operation = new FillItemFieldsFromCallTranscription(
			$fillTarget,
			$summary,
			$context->getUserId(),
			$parentJobId,
		);

		if ($isEmail && $targetOwnerTypeId > 0 && $targetOwnerId > 0)
		{
			$operation
				->setContextExtra('targetOwnerTypeId', $targetOwnerTypeId)
				->setContextExtra('targetOwnerId', $targetOwnerId)
			;
		}

		return $operation;
	}

	/**
	 * Resolves the FillItemFields target.
	 * Manual launch (ALG-01): the clicked entity from the timeline context — no re-resolve, single-target.
	 * Auto path: unchanged TargetResolver priority Deal>Lead resolution.
	 * Delegates to StepContext::resolveFillTarget so StepFactory and StepResultResolver share one rule.
	 */
	private function resolveFillFieldsTarget(StepContext $context): ?ItemIdentifier
	{
		return $context->resolveFillTarget($this->targetResolver);
	}

	/**
	 * The chat baseline that gates (re)creating the summary must match the fill button's per-entity
	 * baseline for fill-bearing scenarios (keyed by the fill target), so a stale summary is regenerated
	 * exactly when the fill button re-enables. Non-fill chat scenarios keep the chat-global baseline.
	 * See fill-fields-any-entity.
	 */
	private function resolveChatBaselineTarget(StepContext $context): ?ItemIdentifier
	{
		if (in_array($context->getScenarioName(), [Scenario::FILL_FIELDS_SCENARIO, Scenario::FULL_SCENARIO], true))
		{
			return $context->resolveFillTarget($this->targetResolver);
		}

		return null;
	}

	/**
	 * Gets transcription from TranscribeCallRecording result.
	 * Passes assessmentSettingsId from context extra.
	 * Returns null if CallAssessment is disabled — PipelineExecutor will skip this step.
	 */
	private function createScoreCall(StepContext $context): ?AbstractOperation
	{
		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment))
		{
			return null;
		}

		if (!$this->shouldAutostartScoreCall($context))
		{
			return null;
		}

		$transcriptionResult = $this->resultResolver->resolve(TranscribeCallRecording::class, $context);
		$transcription = (string)($transcriptionResult?->getPayload()?->transcription ?? '');
		if (empty($transcription))
		{
			return null;
		}

		$assessmentSettingsId = $context->getExtra('assessmentSettingsId');
		$userId = $context->isManualLaunch()
			? $context->getUserId()
			: ($transcriptionResult->getUserId() ?? $context->getUserId())
		;

		return new ScoreCall(
			new ItemIdentifier(CCrmOwnerType::Activity, $context->getActivityId()),
			$transcription,
			$userId,
			$transcriptionResult->getJobId(),
			$assessmentSettingsId !== null ? (int)$assessmentSettingsId : null,
		);
	}

	/**
	 * V2 of ScoreCall — uses setters instead of constructor args (transcription / assessmentSettingsId).
	 * Gating: CallAssessment global setting + auto-start check + non-empty transcription + selected script.
	 * Automatic call scoring is driven by the bizproc call-assessment activity
	 * (AIManager::launchScoreCallV2 with the chosen script); the pipeline must not launch V2 scoring
	 * without a script — see the assessmentSettingsId guard below.
	 */
	private function createScoreCallV2(StepContext $context): ?AbstractOperation
	{
		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::CallAssessment))
		{
			return null;
		}

		if (!$this->shouldAutostartScoreCall($context))
		{
			return null;
		}

		$transcriptionResult = $this->resultResolver->resolve(TranscribeCallRecording::class, $context);
		$transcription = (string)($transcriptionResult?->getPayload()?->transcription ?? '');
		if (empty($transcription))
		{
			return null;
		}

		// V2 scoring must run against an explicitly selected script. The automatic pipeline does not
		// carry the chosen assessment settings id, so launching ScoreCallV2 here would build an empty
		// `dialog_quality_scorer` payload and fail with PAYLOAD_IS_EMPTY. Skip the step when no script
		// is provided — automatic scoring goes through AIManager::launchScoreCallV2 (bizproc activity).
		$assessmentSettingsId = $context->getExtra('assessmentSettingsId');
		if ($assessmentSettingsId === null || (int)$assessmentSettingsId <= 0)
		{
			return null;
		}

		$operation = new ScoreCallV2(
			new ItemIdentifier(CCrmOwnerType::Activity, $context->getActivityId()),
			$transcriptionResult->getUserId() ?? $context->getUserId(),
			$transcriptionResult->getJobId(),
		);

		$operation->setTranscription($transcription);
		$operation->setAssessmentSettingsId((int)$assessmentSettingsId);

		return $operation;
	}

	/**
	 * Gets transcription from TranscribeCallRecording result.
	 * For OpenLine provider, gets chat messages via OpenLine::getMessagesForCopilot().
	 */
	private function createAnalyzeCommunication(StepContext $context): ?AbstractOperation
	{
		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication))
		{
			return null;
		}

		if (!$this->shouldAutostartOperation($context, AnalyzeCommunication::TYPE_ID))
		{
			return null;
		}

		$activityId = $context->getActivityId();
		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);

		$isOpenLine = $context->getActivityProvider() === OpenLine::getId();
		$isEmail = $context->getActivityProvider() === Email::getId();

		if ($isOpenLine)
		{
			$messages = OpenLine::getMessagesForCopilot($activityId);
			if (!OpenLine::isCopilotProcessingAvailable($activityId, $messages, false))
			{
				return null;
			}

			return new AnalyzeCommunication(
				$target,
				$messages,
				$context->getUserId(),
			);
		}

		if ($isEmail)
		{
			$targetOwnerTypeId = (int)$context->getExtra('targetOwnerTypeId');
			$targetOwnerId = (int)$context->getExtra('targetOwnerId');
			if ($targetOwnerTypeId <= 0 || $targetOwnerId <= 0)
			{
				$fallback = $this->targetResolver->findTarget($activityId);
				if ($fallback)
				{
					$targetOwnerTypeId = $fallback->getEntityTypeId();
					$targetOwnerId = $fallback->getEntityId();
				}
			}
			$messages = Email::getMessagesForCopilot(
				$activityId,
				$targetOwnerTypeId,
				$targetOwnerId,
			);
			if (empty($messages))
			{
				return null;
			}

			return (new AnalyzeCommunication(
				$target,
				$messages,
				$context->getUserId(),
			))
				->setContextExtra('targetOwnerTypeId', $targetOwnerTypeId)
				->setContextExtra('targetOwnerId', $targetOwnerId)
			;
		}

		$transcriptionResult = $this->resultResolver->resolve(TranscribeCallRecording::class, $context);
		$transcription = (string)($transcriptionResult?->getPayload()?->transcription ?? '');
		if (empty($transcription))
		{
			return null;
		}

		return new AnalyzeCommunication(
			$target,
			$transcription,
			$transcriptionResult->getUserId() ?? $context->getUserId(),
			$transcriptionResult->getJobId(),
		);
	}

	/**
	 * Returns null — ExtractScoringCriteria requires a prompt string that is not carried in StepContext.
	 * This operation is typically launched directly via AIManager::launchExtractScoringCriteria().
	 */
	private function createExtractScoringCriteria(StepContext $context): ?AbstractOperation
	{
		return null;
	}

	/**
	 * Simple: creates FillRepeatSaleTips with the activity as target and the userId from context.
	 * Mirrors AIManager::launchFillRepeatSaleTips.
	 */
	private function createFillRepeatSaleTips(StepContext $context): ?AbstractOperation
	{
		return new FillRepeatSaleTips(
			new ItemIdentifier(CCrmOwnerType::Activity, $context->getActivityId()),
			$context->getUserId(),
		);
	}

	/**
	 * Simple: creates ScreeningRepeatSaleItem with the target from context extra.
	 * The target (a Deal identifier) must be provided via context extra 'screeningTarget'.
	 * Falls back to a Deal ItemIdentifier built from context extra 'targetEntityTypeId'/'targetEntityId',
	 * or returns null if neither is available.
	 * Mirrors AIManager::launchScreeningRepeatSaleItem.
	 */
	private function createScreeningRepeatSaleItem(StepContext $context): ?AbstractOperation
	{
		/** @var ItemIdentifier|null $target */
		$target = $context->getExtra('screeningTarget');
		if (!$target instanceof ItemIdentifier)
		{
			$entityTypeId = (int)$context->getExtra('targetEntityTypeId', 0);
			$entityId = (int)$context->getExtra('targetEntityId', 0);
			if ($entityTypeId <= 0 || $entityId <= 0)
			{
				return null;
			}

			$target = new ItemIdentifier($entityTypeId, $entityId);
		}

		if (!isset(self::SCREENING_ALLOWED_ENTITY_TYPES[$target->getEntityTypeId()]))
		{
			return null;
		}

		return new ScreeningRepeatSaleItem($target);
	}

	private function shouldAutostartOperation(
		StepContext $context,
		int $operationType,
		?ItemIdentifier $fillTarget = null,
	): bool
	{
		if ($context->isManualLaunch())
		{
			return true;
		}

		$isChatActivity = $context->getActivityProvider() === OpenLine::getId();
		$channelCode = $isChatActivity
			? AutomationScenarioRegistry::CHANNEL_CHAT
			: $this->resolveChannelCode($context)
		;
		if ($channelCode === null)
		{
			return true;
		}

		$activity = $isChatActivity ? null : $this->loadActivity($context->getActivityId());
		if (!$isChatActivity && !is_array($activity))
		{
			return false;
		}

		$direction = (int)($activity['DIRECTION'] ?? 0);
		if ($channelCode === AutomationScenarioRegistry::CHANNEL_EMAIL)
		{
			$triggerDirection = $context->getExtra('triggerDirection');
			if ($triggerDirection !== null)
			{
				$direction = (int)$triggerDirection;
			}
		}

		$fillTarget ??= $this->targetResolver->findTarget($context->getActivityId());
		if (!$fillTarget)
		{
			return false;
		}

		$settings = FillFieldsSettings::get(
			$fillTarget->getEntityTypeId(),
			$fillTarget->getCategoryId(),
		);

		$scenarioCode = $this->resolveScenarioCodeForOperation($operationType);
		if ($scenarioCode !== null)
		{
			$resolver = new ScenarioOverrideResolver();

			return $resolver->isScenarioStepActive(
				$settings,
				$scenarioCode,
				$channelCode,
				$direction,
			) && $resolver->isCallPrerequisiteActive(
				$settings,
				$scenarioCode,
				$channelCode,
				$direction,
			);
		}

		if ($isChatActivity)
		{
			return true;
		}

		return $settings->shouldAutostart(
			$operationType,
			$direction,
			false,
		);
	}

	private function resolveChannelCode(StepContext $context): ?string
	{
		$activityProvider = $context->getActivityProvider();
		if ($activityProvider === null)
		{
			$activity = $this->loadActivity($context->getActivityId());
			$activityProvider = is_array($activity) ? ($activity['PROVIDER_ID'] ?? null) : null;
		}

		return match ($activityProvider) {
			Call::getId() => AutomationScenarioRegistry::CHANNEL_CALL,
			Email::getId() => AutomationScenarioRegistry::CHANNEL_EMAIL,
			default => null,
		};
	}

	private function shouldAutostartScoreCall(StepContext $context): bool
	{
		if ($context->isManualLaunch() || !$this->isCallActivity($context))
		{
			return true;
		}

		$activity = $this->loadActivity($context->getActivityId());
		$scoreCallSettings = $this->getScoreCallSettingsByActivity($context->getActivityId());
		if (!is_array($activity) || !$scoreCallSettings)
		{
			return false;
		}

		return $scoreCallSettings->shouldAutostart(
			ScoreCall::TYPE_ID,
			(int)($activity['DIRECTION'] ?? 0),
		);
	}

	private function resolveScenarioCodeForOperation(int $operationType): ?string
	{
		return match ($operationType) {
			SummarizeCallTranscription::TYPE_ID => AutomationScenarioRegistry::SCENARIO_SUMMARIZE,
			FillItemFieldsFromCallTranscription::TYPE_ID => AutomationScenarioRegistry::SCENARIO_FILL_FIELDS,
			AnalyzeCommunication::TYPE_ID => AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION,
			default => null,
		};
	}

	private function isCallActivity(StepContext $context): bool
	{
		$activityProvider = $context->getActivityProvider();
		if ($activityProvider === null)
		{
			$activity = $this->loadActivity($context->getActivityId());
			$activityProvider = is_array($activity) ? ($activity['PROVIDER_ID'] ?? null) : null;
		}

		return $activityProvider === Call::getId();
	}

	private function loadActivity(int $activityId): ?array
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);

		return is_array($activity) ? $activity : null;
	}

	private function getScoreCallSettingsByActivity(int $activityId): ?ScoreCallSettings
	{
		if ($activityId <= 0)
		{
			return null;
		}

		$callAssessmentItem = ItemFactory::getByActivityId($activityId);
		$checkerResult = CallAssessmentItemChecker::getInstance()->setItem($callAssessmentItem)->run();
		if (!$checkerResult->isSuccess())
		{
			return null;
		}

		return new ScoreCallSettings($callAssessmentItem?->getAutoCheckTypeId());
	}
	// endregion
}
