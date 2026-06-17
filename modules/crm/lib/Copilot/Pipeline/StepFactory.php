<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\Pipeline;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItemChecker;
use Bitrix\Crm\Copilot\CallAssessment\ItemFactory;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AbstractOperation;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScoreCallSettings;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
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
			!$this->shouldAutostartCallOperation($context, SummarizeCallTranscription::TYPE_ID)
			&& !$this->shouldAutostartCallOperation($context, FillItemFieldsFromCallTranscription::TYPE_ID)
		)
		{
			return null;
		}

		$activityId = $context->getActivityId();
		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);

		$isOpenLine = $context->getActivityProvider() === OpenLine::getId();

		if ($isOpenLine)
		{
			$messages = OpenLine::getMessagesForCopilot($activityId);
			if (!OpenLine::isCopilotProcessingAvailable($activityId, $messages))
			{
				return null;
			}

			return new SummarizeCallTranscription(
				$target,
				$messages,
				$context->getUserId(),
			);
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
	 * Uses TargetResolver for Deal/Lead target.
	 * Gets summary from SummarizeCallTranscription result.
	 * Returns null if FillItemFromCall is disabled or autostart settings don't allow it.
	 * PipelineExecutor will skip this step and proceed to the next one (e.g., ScoreCall).
	 */
	private function createFillFields(StepContext $context): ?AbstractOperation
	{
		if (!AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall))
		{
			return null;
		}

		$fillTarget = $this->targetResolver->findTarget($context->getActivityId());
		if (!$fillTarget)
		{
			return null;
		}

		if (!$this->shouldAutostartCallOperation($context, FillItemFieldsFromCallTranscription::TYPE_ID, $fillTarget))
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

		return new FillItemFieldsFromCallTranscription(
			$fillTarget,
			$summary,
			$context->getUserId(),
			$parentJobId,
		);
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

		return new ScoreCall(
			new ItemIdentifier(CCrmOwnerType::Activity, $context->getActivityId()),
			$transcription,
			$transcriptionResult->getUserId() ?? $context->getUserId(),
			$transcriptionResult->getJobId(),
			$assessmentSettingsId !== null ? (int)$assessmentSettingsId : null,
		);
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

		if (!$this->shouldAutostartCallOperation($context, AnalyzeCommunication::TYPE_ID))
		{
			return null;
		}

		$activityId = $context->getActivityId();
		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);

		$isOpenLine = $context->getActivityProvider() === OpenLine::getId();

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

	private function shouldAutostartCallOperation(
		StepContext $context,
		int $operationType,
		?ItemIdentifier $fillTarget = null,
	): bool
	{
		if ($context->isManualLaunch() || !$this->isCallActivity($context))
		{
			return true;
		}

		$activity = $this->loadActivity($context->getActivityId());
		if (!is_array($activity))
		{
			return false;
		}

		$fillTarget ??= $this->targetResolver->findTarget($context->getActivityId());
		if (!$fillTarget)
		{
			return false;
		}

		return FillFieldsSettings::get(
			$fillTarget->getEntityTypeId(),
			$fillTarget->getCategoryId()
		)->shouldAutostart(
			$operationType,
			(int)($activity['DIRECTION'] ?? 0),
			false,
		);
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
