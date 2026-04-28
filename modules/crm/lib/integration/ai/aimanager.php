<?php

namespace Bitrix\Crm\Integration\AI;

use Bitrix\AI\Agreement;
use Bitrix\AI\Context;
use Bitrix\AI\Context\Language;
use Bitrix\AI\Engine;
use Bitrix\AI\Services\CopilotNameService;
use Bitrix\AI\Tuning\Manager;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\ExtractScoringCriteria;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\FillRepeatSaleTips;
use Bitrix\Crm\Integration\AI\Operation\Sandbox;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\ScoreCall;
use Bitrix\Crm\Integration\AI\Operation\ScreeningRepeatSaleItem;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\TranscribeCallRecording;
use Bitrix\Crm\Integration\Bitrix24Manager;
use Bitrix\Crm\Integration\Market\Router;
use Bitrix\Crm\Integration\StorageType;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use CCrmOwnerType;
use Psr\Log\LoggerInterface;

class AIManager
{
	public const AI_COPILOT_FEATURE_NAME = 'crm_copilot';
	public const SUPPORTED_ENTITY_TYPE_IDS = FillItemFieldsFromCallTranscription::SUPPORTED_TARGET_ENTITY_TYPE_IDS;
	public const AI_LICENCE_FEATURE_NAME = 'ai_available_by_version';
	public const AI_COPILOT_FEATURE_RESTRICTED_SLIDER_CODE = 'limit_v2_crm_copilot_call_assessment';

	public const AI_LIMIT_CODE_DAILY = 'Daily';
	public const AI_LIMIT_CODE_MONTHLY = 'Monthly';
	public const AI_LIMIT_BAAS = 'BAAS';

	private const AI_CALL_PROCESSING_AUTOMATICALLY_OPTION_NAME = 'AI_CALL_PROCESSING_ALLOWED_AUTO_V2';
	private const AI_APP_COLLECTION_MARKET_MAP = [
		'ru' => 19021440,
		'by' => 19021806,
		'kz' => 19021810,
	];
	private const AI_APP_COLLECTION_MARKET_DEFAULT = 19021800;

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

		static $manager = null;
		if (!$manager)
		{
			$manager = new Manager();
		}

		$item = $manager->getItem($setting->value);

		return isset($item) && $item->getValue();
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

			$storageElementIds = \CCrmActivity::extractStorageElementIds($activity) ?? [];
			if (!empty($storageElementIds))
			{
				$storageElementId = max($storageElementIds);
			}
		}

		if (!StorageType::isDefined($storageTypeId) || $storageElementId <= 0)
		{
			return $result->addError(ErrorCode::getFileNotFoundError());
		}

		return (new TranscribeCallRecording(
			$itemIdentifier,
			$storageTypeId,
			$storageElementId,
			$userId,
		))
			->setIsManualLaunch($isManualLaunch)
			->setScenario($scenario)
			->launch()
		;
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
		$result = new Result(Operation\AbstractFillRepeatSaleTips::TYPE_ID);

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
		$result = new Result(Operation\AbstractFillRepeatSaleTips::TYPE_ID);

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
			->setScenario(Scenario::REPEAT_SALE_SCREENING_SCENARIO)
			->launch()
		;
	}

	public static function launchSummarizeData(int $activityId, ?int $userId = null, bool $isManualLaunch = true): Result
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

		return (new SummarizeCallTranscription(
			$itemIdentifier,
			$messages,
			$userId
		))
			->setIsManualLaunch($isManualLaunch)
			->setScenario(Scenario::FILL_FIELDS_SCENARIO)
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
			ExtractScoringCriteria::TYPE_ID,
			FillRepeatSaleTips::TYPE_ID,
			ScreeningRepeatSaleItem::TYPE_ID,
			Sandbox\FillRepeatSaleTips::TYPE_ID,
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
				'sliderCode' => BaasManager::getEmptyPackagesSliderCode(),
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
}
