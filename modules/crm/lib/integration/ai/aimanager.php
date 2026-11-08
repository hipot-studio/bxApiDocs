<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Agreement;
use Bitrix\AI\Context;
use Bitrix\AI\Context\Language;
use Bitrix\AI\Engine;
use Bitrix\AI\Enum\VibePlusLimitState;
use Bitrix\AI\Services\CopilotNameService;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Copilot\CallScriptEditReview\EditReviewRepository;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\CallScoringV2;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallCriteria;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallScriptDescription;
use Bitrix\Crm\Integration\AI\Operation\GenerateCallScriptFromDialog;
use Bitrix\Crm\Integration\AI\Operation\GenerateManagerSummary;
use Bitrix\Crm\Integration\AI\Operation\GroupSuspiciousCalls;
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScoreCallV2;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SelectCallScoreScript;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Integration\Market\Router;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Type\DateTime;
use CCrmActivity;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

class AIManager
{
	public const AI_COPILOT_FEATURE_NAME = 'crm_copilot';
	/**
	 * @deprecated Kept for BC. Use {@see AIManager::isEntityTypeSupported()} as the single source of truth
	 *             for the fill-fields scenario entity-type gate.
	 */
	public const SUPPORTED_ENTITY_TYPE_IDS = FillItemFieldsFromCallTranscription::SUPPORTED_TARGET_ENTITY_TYPE_IDS;
	public const AI_LICENCE_FEATURE_NAME = 'ai_available_by_version';
	public const AI_COPILOT_FEATURE_RESTRICTED_SLIDER_CODE = 'limit_v2_crm_copilot_call_assessment';

	public const AI_LIMIT_CODE_DAILY = 'Daily';
	public const AI_LIMIT_CODE_MONTHLY = 'Monthly';
	public const AI_LIMIT_BAAS = 'BAAS';

	private const AI_CALL_PROCESSING_AUTOMATICALLY_OPTION_NAME = 'AI_CALL_PROCESSING_ALLOWED_AUTO_V2';
	public const CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME = 'CALL_SCORING_V2_PENDING_BACKFILL';
	private const AI_APP_COLLECTION_MARKET_MAP = [
		'ru' => 19021440,
		'by' => 19021806,
		'kz' => 19021810,
	];
	private const AI_APP_COLLECTION_MARKET_DEFAULT = 19021800;

	private static ?Manager $globalSettingsTuningManager = null;

	public static function isAvailable(): bool
	{
		return self::isAvailableRegion() && Loader::includeModule('ai');
	}

	public static function isAvailableRegion(): bool
	{
		$regionBlacklist = [
			'ua',
			'cn',
		];

		$region = Application::getInstance()->getLicense()->getRegion();
		if ($region === null)
		{
			return false; // block AI in unknown region just in case
		}

		return !in_array(mb_strtolower($region), $regionBlacklist, true);
	}

	/**
	 * Single source of truth: whether the given CRM entity type is a valid target for the
	 * "fill fields" scenario.
	 *
	 * Replaces the hardcoded Deal/Lead whitelist ({@see AIManager::SUPPORTED_ENTITY_TYPE_IDS}).
	 * The scenario is allowed for any entity backed by the universal CRM model (Factory-based),
	 * which also covers smart processes; those entities are exactly the ones that accept activity
	 * bindings. The factory-presence check guards against dynamic type ids with no real type
	 * created. `CCrmOwnerType::isPossibleActivityOwner()` does not exist in this codebase, so the
	 * factual equivalent (Factory-based + existing factory) is used.
	 */
	public static function isEntityTypeSupported(int $entityTypeId): bool
	{
		return $entityTypeId > 0
			&& CCrmOwnerType::isUseFactoryBasedApproach($entityTypeId)
			&& Container::getInstance()->getFactory($entityTypeId) !== null
		;
	}

	public static function isEnabledInGlobalSettings(string|GlobalSetting $code = GlobalSetting::FillItemFromCall): bool
	{
		if (!static::isAvailable())
		{
			return false;
		}

		$setting = is_string($code) ? GlobalSetting::tryFrom($code) : $code;
		if ($setting === null)
		{
			return false;
		}

		if (
			$setting === GlobalSetting::FillCrmText
			&& !static::isEngineAvailable(EventHandler::ENGINE_CATEGORY)
		)
		{
			return false;
		}

		if (self::$globalSettingsTuningManager === null)
		{
			self::$globalSettingsTuningManager = new Manager();
		}

		$item = self::$globalSettingsTuningManager->getItem($setting->value);

		return isset($item) && $item->getValue();
	}

	public static function isFillFieldsEnabledForEntityType(int $entityTypeId): bool
	{
		return self::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall)
			&& CopilotSettings::getCurrent()->isFillFieldsEnabledFor($entityTypeId)
		;
	}

	public static function isCallTranscriptionEngineConfigured(): bool
	{
		if (!static::isAvailable())
		{
			return false;
		}

		if (self::$globalSettingsTuningManager === null)
		{
			self::$globalSettingsTuningManager = new Manager();
		}

		$item = self::$globalSettingsTuningManager->getItem(
			EventHandler::SETTINGS_FILL_ITEM_FROM_CALL_ENGINE_AUDIO_CODE,
		);

		return isset($item) && !empty($item->getValue());
	}

	public static function isEngineAvailable(string $type): bool
	{
		if (!static::isAvailable())
		{
			return false;
		}

		$engine = Engine::getByCategory($type, Context::getFake());
		if (!$engine)
		{
			return false;
		}

		return true;
	}

	public static function isAiCallProcessingEnabled(): bool
	{
		static $result = null;

		if (is_null($result))
		{
			$result = static::isAvailable()
				&& Bitrix24Manager::isFeatureEnabled(self::AI_COPILOT_FEATURE_NAME)
			;
		}

		return $result;
	}

	public static function isAiCallAutomaticProcessingAllowed(): bool
	{
		return
			static::isAiCallProcessingEnabled()
			&& Option::get('crm', self::AI_CALL_PROCESSING_AUTOMATICALLY_OPTION_NAME, BaasManager::isAvailable())
		;
	}

	public static function isCallScoringV2Enabled(): bool
	{
		return Feature::enabled(CallScoringV2::class) && !self::isCallScoringV2BackfillPending();
	}

	public static function isCallScoringV2BackfillPending(): bool
	{
		return Option::get('crm', self::CALL_SCORING_V2_PENDING_BACKFILL_OPTION_NAME, 'N') === 'Y';
	}

	public static function isAILicenceAccepted(int $userId = null): bool
	{
		if (static::isAvailable())
		{
			// check for box instances
			if (\Bitrix\Crm\Settings\Crm::isBox())
			{
				if (!method_exists(Agreement::class, 'isAcceptedByUser'))
				{
					return true;
				}

				$userId = $userId ?? Container::getInstance()->getContext()->getUserId();

				return Agreement::get('AI_BOX_AGREEMENT')?->isAcceptedByUser($userId) ?? false;
			}

			// check for cloud instances
			return Bitrix24Manager::isFeatureEnabled(self::AI_LICENCE_FEATURE_NAME);
		}

		return false;
	}

	public static function setAiCallAutomaticProcessingAllowed(?bool $isAllowed): void
	{
		if (is_null($isAllowed))
		{
			Option::delete('crm', ['name' => self::AI_CALL_PROCESSING_AUTOMATICALLY_OPTION_NAME]);
		}
		else
		{
			Option::set('crm', self::AI_CALL_PROCESSING_AUTOMATICALLY_OPTION_NAME, $isAllowed);
		}
	}

	public static function isStubMode(): bool
	{
		return Option::get('crm', 'dev_ai_stub_mode', 'N') === 'Y';
	}

	public static function registerStubJob(Engine $engine, mixed $payload): string
	{
		$hash = md5(Random::getString(10, true));

		Application::getInstance()->addBackgroundJob(static function() use ($hash, $engine, $payload) {
			$result = new \Bitrix\AI\Result($payload, $payload);

			$event = new Event(
				'ai',
				'onQueueJobExecute',
				[
					'queue' => $hash,
					'engine' => $engine->getIEngine(),
					'result' => $result,
					'error' => null,
				]
			);

			$waitTime = (int)Option::get('crm', 'dev_ai_stub_mode_wait_time', 3);
			if ($waitTime > 0)
			{
				sleep($waitTime);
			}

			$event->send();
		});

		return $hash;
	}

	// region launch scenario
	public static function launchCallRecordingTranscription(
		int $activityId,
		string $scenario,
		?int $userId = null,
		?int $storageTypeId = null,
		?int $storageElementId = null,
		bool $isManualLaunch = true,
		?string $launchSource = null,
		array $contextExtra = [],
	): Result
	{
		$result = new Result(TranscribeCallRecording::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($activityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$itemIdentifier = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);
		if (!TranscribeCallRecording::isSuitableTarget($itemIdentifier))
		{
			return $result->addError(ErrorCode::getNotSuitableTargetError());
		}

		if (!StorageType::isDefined($storageTypeId) || $storageElementId <= 0)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
			if (!is_array($activity))
			{
				return $result->addError(ErrorCode::getNotFoundError());
			}

			$storageTypeId = $activity['STORAGE_TYPE_ID'] ?? null;

			$storageElementIds = CCrmActivity::extractStorageElementIds($activity) ?? [];
			if (!empty($storageElementIds))
			{
				$storageElementId = max($storageElementIds);
			}
		}

		if (!StorageType::isDefined($storageTypeId) || $storageElementId <= 0)
		{
			return $result->addError(ErrorCode::getFileNotFoundError());
		}

		$operation = (new TranscribeCallRecording(
			$itemIdentifier,
			$storageTypeId,
			$storageElementId,
			$userId,
		))
			->setLaunchSource($launchSource)
			->setIsManualLaunch($isManualLaunch)
			->setScenario($scenario)
		;
		foreach ($contextExtra as $key => $value)
		{
			$operation->setContextExtra($key, $value);
		}

		return $operation->launch();
	}

	public static function launchExtractScoringCriteria(int $entityId, string $prompt, ?int $userId = null, bool $isManualLaunch = true): Result
	{
		$result = new Result(ExtractScoringCriteria::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($entityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		if (empty($prompt))
		{
			return $result->addError(new Error('Prompt cannot be empty', ErrorCode::INVALID_ARG_VALUE));
		}

		return (new ExtractScoringCriteria(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $entityId),
			$prompt,
			$userId
		))
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::EXTRACT_SCORING_CRITERIA_SCENARIO)
			->launch()
		;
	}

	public static function launchFillRepeatSaleTips(int $activityId, ?int $userId = null, bool $isManualLaunch = false): Result
	{
		$result = new Result(Operation\AbstractOperation::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($activityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		return (new FillRepeatSaleTips(
			new ItemIdentifier(CCrmOwnerType::Activity, $activityId),
			$userId,
		))
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::REPEAT_SALE_TIPS_SCENARIO)
			->launch()
		;
	}

	public static function launchSandboxFillRepeatSaleTips(
		ItemIdentifier $itemIdentifier,
		ItemIdentifier $clientIdentifier,
		int $segmentId,
		?int $userId = null,
	): Result
	{
		$result = new Result(Operation\AbstractOperation::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		return (new Operation\Sandbox\FillRepeatSaleTips($itemIdentifier, $userId))
			->setSegmentId($segmentId)
			->setClientIdentifier($clientIdentifier)
			->setIsManualLaunch(true)
			->setScenario(Scenario::REPEAT_SALE_TIPS_SCENARIO)
			->launch()
		;
	}

	public static function launchScreeningRepeatSaleItem(
		ItemIdentifier $targetItemIdentifier,
		int $segmentId = 0,
		array $clientIdentifiers = [],
		bool $isManualLaunch = false,
	): Result
	{
		$result = new Result(ScreeningRepeatSaleItem::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		$availabilityChecker = Container::getInstance()->getRepeatSaleAvailabilityChecker();
		if (!$availabilityChecker->isAvailable() || !$availabilityChecker->isAiSegmentsAvailable())
		{
			return $result->addError(new Error('Repeat Sale feature is not available'));
		}

		return (new ScreeningRepeatSaleItem($targetItemIdentifier))
			->setSegmentId($segmentId)
			->setClientIdentifiers($clientIdentifiers)
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::REPEAT_SALE_SCREENING_SCENARIO)
			->launch()
		;
	}

	// @todo - remove and fix tests (crm/tests/lib/integration/ai/aimanagerlaunchmethodstest.php)
	public static function launchSummarizeDataInChat(
		int $activityId,
		?int $userId = null,
		bool $isManualLaunch = true,
		string $scenario = Scenario::SUMMARIZE_SCENARIO,
	): Result
	{
		$result = new Result(SummarizeCallTranscription::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($activityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$itemIdentifier = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);
		if (!SummarizeCallTranscription::isSuitableTarget($itemIdentifier))
		{
			return $result->addError(ErrorCode::getNotSuitableTargetError());
		}

		$messages = OpenLine::getMessagesForCopilot($activityId);
		if (!OpenLine::isCopilotProcessingAvailable($activityId, $messages))
		{
			return $result->addError(ErrorCode::getNotEnoughMessagesError());
		}

		// Direct summarize launch is terminal. Scenario chains with follow-up steps must go through PipelineExecutor.
		return (new SummarizeCallTranscription(
			$itemIdentifier,
			$messages,
			$userId
		))
			->setIsManualLaunch($isManualLaunch)
			->setScenario($scenario)
			->setNextTypeIdOverride(0)
			->launch()
		;
	}

	public static function launchSelectCallScoringScript(
		int $activityId,
		string $transcription,
		?int $userId = null,
		bool $isManualLaunch = false,
		array $assessmentSettingsIds = [],
	): Result
	{
		$result = new Result(SelectCallScoreScript::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($activityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$target = new ItemIdentifier(CCrmOwnerType::Activity, $activityId);
		if (!SelectCallScoreScript::isSuitableTarget($target))
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$operation = (new SelectCallScoreScript(
			$target,
			$userId,
		))
			->setTranscription($transcription)
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::SELECT_CALL_SCORING_SCRIPT_SCENARIO)
		;

		if (!empty($assessmentSettingsIds))
		{
			$operation->setAssessmentSettingsIds($assessmentSettingsIds);
		}

		return $operation->launch();
	}

	public static function launchScoreCallV2(
		int $activityId,
		string $transcription,
		int $assessmentSettingsId,
		?int $userId = null,
		bool $isManualLaunch = false,
	): Result
	{
		$result = new Result(ScoreCallV2::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($activityId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$operation = (new ScoreCallV2(
			new ItemIdentifier(CCrmOwnerType::Activity, $activityId),
			$userId,
		))
			->setTranscription($transcription)
			->setAssessmentSettingsId($assessmentSettingsId)
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::CALL_SCORING_V2_SCENARIO)
		;

		return $operation->launch();
	}

	public static function launchGenerateCallCriteria(
		int $assessmentSettingsId,
		array $dialogues,
		?int $userId = null,
		bool $isManualLaunch = false,
	): Result
	{
		$result = new Result(GenerateCallCriteria::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($assessmentSettingsId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		$operation = (new GenerateCallCriteria(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $assessmentSettingsId),
			$userId,
		))
			->setDialogues($dialogues)
			->setIsManualLaunch($isManualLaunch)
		;

		return $operation->launch();
	}

	public static function launchReviewCallScriptAfterEdit(
		int $assessmentSettingsId,
		?int $userId = null,
	): Result
	{
		$result = new Result(GenerateCallScriptDescription::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($assessmentSettingsId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		EditReviewRepository::getInstance()->clear($assessmentSettingsId);

		$operation = new GenerateCallScriptDescription($assessmentSettingsId, $userId);

		$inputResult = $operation->checkRequiredInput();
		if (!$inputResult->isSuccess())
		{
			return $result->addErrors($inputResult->getErrors());
		}

		return $operation->setIsManualLaunch(false)->launch();
	}

	/**
	 * @param int[] $clientTypeIds
	 */
	public static function launchGenerateCallScriptFromDialog(
		string $userText,
		int $assessmentId,
		?int $userId = null,
	): Result
	{
		$result = new Result(GenerateCallScriptFromDialog::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		return (new GenerateCallScriptFromDialog($assessmentId, $userId))
			->setUserText($userText)
			->launch()
		;
	}

	/**
	 * @param array<int, array{id: int, data: array{theme: ?string, product: ?string, intent: ?string}}> $calls
	 * @param array<int, array{id: int, name: string, description: string}> $scripts
	 */
	public static function launchGroupSuspiciousCalls(
		array $calls,
		array $scripts,
		?int $userId = null,
	): Result
	{
		$result = new Result(GroupSuspiciousCalls::TYPE_ID);

		if (!self::isCallScoringV2Enabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		return (new GroupSuspiciousCalls($userId))
			->setCalls($calls)
			->setScripts($scripts)
			->setIsManualLaunch(false)
			->launch()
		;
	}

	public static function launchGenerateManagerSummary(
		int $managerId,
		?int $userId = null,
		?DateTime $referenceDate = null,
	): Result
	{
		$result = new Result(GenerateManagerSummary::TYPE_ID);

		if (!static::isAvailable() || !static::isAiCallProcessingEnabled())
		{
			return $result->addError(ErrorCode::getAINotAvailableError());
		}

		if ($managerId <= 0)
		{
			return $result->addError(ErrorCode::getNotFoundError());
		}

		// Idempotency shortcut: if the manager's data has not changed since the last summary,
		// re-deliver the cached result instead of spending a new AI request. The reference date
		// keeps the dedup window aligned with the window the fresh generation would use.
		if (GenerateManagerSummary::deliverCachedIfFresh($managerId, (int)$userId, $referenceDate))
		{
			return $result;
		}

		return (new GenerateManagerSummary($managerId, $userId, null, $referenceDate))
			->setIsManualLaunch(false)
			->launch()
		;
	}
	// endregion

	public static function getAllOperationTypes(): array
	{
		return [
			TranscribeCallRecording::TYPE_ID,
			SummarizeCallTranscription::TYPE_ID,
			FillItemFieldsFromCallTranscription::TYPE_ID,
			ScoreCall::TYPE_ID,
			ScoreCallV2::TYPE_ID,
			SelectCallScoreScript::TYPE_ID,
			ExtractScoringCriteria::TYPE_ID,
			GenerateCallCriteria::TYPE_ID,
			GenerateCallScriptFromDialog::TYPE_ID,
			GenerateCallScriptDescription::TYPE_ID,
			GroupSuspiciousCalls::TYPE_ID,
			GenerateManagerSummary::TYPE_ID,
			FillRepeatSaleTips::TYPE_ID,
			ScreeningRepeatSaleItem::TYPE_ID,
			Sandbox\FillRepeatSaleTips::TYPE_ID,
			AnalyzeCommunication::TYPE_ID,
		];
	}

	public static function logger(): LoggerInterface
	{
		return Container::getInstance()->getLogger('Integration.AI');
	}

	public static function fetchLimitError(Error $error): ?Error
	{
		$errorCode = $error->getCode();
		$errorMessage = $error->getMessage();
		$customData = $error->getCustomData();

		if ($errorCode === 'RATE_LIMIT' && !empty($customData['sliderCode']))
		{
			return ErrorCode::getAILimitOfRequestsExceededError(
				[
					'sliderCode' => $customData['sliderCode']
				],
				$errorMessage
			);
		}

		if (!str_starts_with($errorCode, 'LIMIT_IS_EXCEEDED'))
		{
			return null;
		}

		$vibePlusLimitState = $customData['vibePlusLimitState'] ?? null;
		if (
			!empty($vibePlusLimitState)
			&& $vibePlusLimitState !== VibePlusLimitState::NotApplicable->name
			&& !in_array(
				$errorCode,
				['LIMIT_IS_EXCEEDED_BAAS', 'LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT'],
				true,
			)
		)
		{
			$vibePlusCustomData = [
				'vibePlusLimitState' => $vibePlusLimitState,
				'showSliderWithMsg' => $customData['showSliderWithMsg'] ?? null,
				'msgForIm' => $customData['msgForIm'] ?? null,
			];

			$limitCode = match ($errorCode)
			{
				'LIMIT_IS_EXCEEDED_DAILY' => self::AI_LIMIT_CODE_DAILY,
				'LIMIT_IS_EXCEEDED_MONTHLY' => self::AI_LIMIT_CODE_MONTHLY,
				default => null,
			};
			if ($limitCode !== null)
			{
				$vibePlusCustomData['limitCode'] = $limitCode;
			}

			if (
				in_array(
					$vibePlusLimitState,
					[VibePlusLimitState::BuyWithDemo->name, VibePlusLimitState::BuyWithoutDemo->name],
					true,
				)
				&& !empty($customData['sliderCode'])
			)
			{
				$vibePlusCustomData['sliderCode'] = $customData['sliderCode'];
			}

			return ErrorCode::getAILimitOfRequestsExceededError($vibePlusCustomData);
		}

		if (!empty($customData['sliderCode']))
		{
			$sliderCode = $customData['sliderCode'];

			if (!empty($customData['showSliderWithMsg']))
			{
				return ErrorCode::getAILimitOfRequestsExceededError([
					'sliderCode' => $sliderCode,
				]);
			}
		}

		return match ($errorCode)
		{
			'LIMIT_IS_EXCEEDED_BAAS' => ErrorCode::getAILimitOfRequestsExceededError([
				'sliderCode' => BaasManager::isAvailable()
					? BaasManager::getEmptyPackagesSliderCode()
					: BaasManager::SLIDER_CODE_EMPTY_BAAS_PACKAGES,
				'limitCode' => self::AI_LIMIT_BAAS,
			]),
			'LIMIT_IS_EXCEEDED_MONTHLY' => ErrorCode::getAILimitOfRequestsExceededError([
				'sliderCode' => $sliderCode ?? BaasManager::SLIDER_CODE_LIMIT_MONTHLY,
				'limitCode' => self::AI_LIMIT_CODE_MONTHLY,
			]),
			'LIMIT_IS_EXCEEDED_DAILY' => ErrorCode::getAILimitOfRequestsExceededError([
				'sliderCode' => BaasManager::SLIDER_CODE_LIMIT_DAILY,
				'limitCode' => self::AI_LIMIT_CODE_DAILY,
			]),
			'LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT' => new Error($errorMessage, ErrorCode::AI_ENGINE_LIMIT_EXCEEDED),
			default => ErrorCode::getAILimitOfRequestsExceededError(),
		};
	}

	public static function getAiAppCollectionMarketLink(): string
	{
		$region = mb_strtolower(Application::getInstance()->getLicense()->getRegion());
		$collectionId = self::AI_APP_COLLECTION_MARKET_MAP[$region] ?? self::AI_APP_COLLECTION_MARKET_DEFAULT;

		return Router::getBasePath() . 'collection/' . $collectionId . '/';
	}

	public static function getAvailableLanguageList(): array
	{
		if (static::isAvailable())
		{
			return Language::getAvailable();
		}

		return [];
	}

	public static function getCopilotName(): string
	{
		if (static::isAvailable())
		{
			return (new CopilotNameService())->getCopilotName();
		}

		return '';
	}

	/**
	 * @internal Resets the cached Tuning\Manager so that subsequent calls re-read the `ai.tuning` option.
	 *           Call after changing global AI settings through another Tuning\Manager instance.
	 */
	public static function resetGlobalSettingsCache(): void
	{
		self::$globalSettingsTuningManager = null;
	}
}
