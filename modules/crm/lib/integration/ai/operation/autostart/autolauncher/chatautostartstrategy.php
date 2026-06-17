<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\AnalyzeCommunication;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings\ChatChannelSettings;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Integration\AI\Operation\SummarizeCallTranscription;
use Bitrix\Main\DI\ServiceLocator;

final class ChatAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getFillFieldsSettings();
		if ($fillFieldsSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$fillFieldsSettingsChat = $fillFieldsSettings->getChannelSettings(ChatChannelSettings::CHANNEL_TYPE);
		if (!$fillFieldsSettingsChat instanceof ChatChannelSettings)
		{
			$this->logger->debug('{date}: Unable to get chat autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$scenario = $this->detectLaunchScenario($fillFieldsSettingsChat);
		if ($scenario === Scenario::UNDEFINED_SCENARIO)
		{
			return;
		}

		$this->logger->info(
			'{date}: Trying to autostart operation after completing the open line dialog.'
			. ' Autostart fill fields settings {fillFieldsSettings}, scenario {scenario},'
			. ' changed fields {changedFields}, new activity state {activity}' . PHP_EOL,
			[
				'fillFieldsSettings' => $fillFieldsSettingsChat,
				'scenario' => $scenario,
				'activity' => $this->activityFields,
				'changedFields' => $changedFields,
			],
		);

		$activityId = (int)($this->activityFields['ID'] ?? null);
		if (!$this->isLaunchPossible($activityId))
		{
			$this->logger->debug('{date}: Unable to autostart operation: AI operation in CRM is not possible' . PHP_EOL);

			return;
		}

		$this->logger->info(
			'{date}: Trying to autostart chat operation with scenario "{scenario}"' . PHP_EOL,
			['scenario' => $scenario],
		);

		ServiceLocator::getInstance()->get(PipelineExecutor::class)->startOrResume(
			new StepContext(
				activityId: $activityId,
				userId: $this->userId,
				scenarioName: $scenario,
				isManualLaunch: false,
				activityProvider: OpenLine::getId(),
			),
		);
	}

	private function detectLaunchScenario(ChatChannelSettings $chatSettings): string
	{
		$isFillFieldsEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall);
		$isAnalyzeCommunicationEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication);
		$isSummarizeEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize);

		$shouldAutostartFillFields = $chatSettings->shouldAutostart(FillItemFieldsFromCallTranscription::TYPE_ID);
		$shouldAutostartAnalyzeCommunication = $chatSettings->shouldAutostart(AnalyzeCommunication::TYPE_ID);
		$shouldAutostartSummarize = $chatSettings->shouldAutostart(SummarizeCallTranscription::TYPE_ID);

		if (
			!$shouldAutostartFillFields
			&& !$shouldAutostartAnalyzeCommunication
			&& !$shouldAutostartSummarize
		)
		{
			return Scenario::UNDEFINED_SCENARIO;
		}

		$isFirstChat = !$chatSettings->isAutostartOnlyFirstChat() || $this->isFirstOpenLineActivityForItem();

		return self::resolveLaunchScenarioByOperationTypes(
			$chatSettings->getOperationTypes(),
			$isFirstChat,
			$isFillFieldsEnabled,
			$isAnalyzeCommunicationEnabled,
			$isSummarizeEnabled,
		);
	}

	private static function resolveLaunchScenarioByOperationTypes(
		array $operationTypes,
		bool $isFirstChat,
		bool $isFillFieldsEnabled,
		bool $isAnalyzeCommunicationEnabled,
		bool $isSummarizeEnabled,
	): string
	{
		$shouldFillFieldsStart = $isFillFieldsEnabled
			&& $isSummarizeEnabled
			&& in_array(FillItemFieldsFromCallTranscription::TYPE_ID, $operationTypes, true)
			&& $isFirstChat
		;

		$shouldAnalyzeCommunicationStart = $isAnalyzeCommunicationEnabled
			&& in_array(AnalyzeCommunication::TYPE_ID, $operationTypes, true)
			&& $isFirstChat
		;

		$shouldSummarizeStart = !$shouldFillFieldsStart
			&& $isSummarizeEnabled
			&& in_array(SummarizeCallTranscription::TYPE_ID, $operationTypes, true)
			&& $isFirstChat
		;

		return self::resolveLaunchScenario(
			$shouldFillFieldsStart,
			$shouldAnalyzeCommunicationStart,
			$shouldSummarizeStart,
		);
	}

	private static function resolveLaunchScenario(
		bool $shouldFillFieldsStart,
		bool $shouldAnalyzeCommunicationStart,
		bool $shouldSummarizeStart,
	): string
	{
		$enabledCount = (int)$shouldFillFieldsStart
			+ (int)$shouldAnalyzeCommunicationStart
			+ (int)$shouldSummarizeStart
		;

		if ($enabledCount > 1)
		{
			return Scenario::FULL_SCENARIO;
		}

		if ($shouldFillFieldsStart)
		{
			return Scenario::FILL_FIELDS_SCENARIO;
		}

		if ($shouldAnalyzeCommunicationStart)
		{
			return Scenario::ANALYZE_COMMUNICATION_SCENARIO;
		}

		if ($shouldSummarizeStart)
		{
			return Scenario::SUMMARIZE_SCENARIO;
		}

		return Scenario::UNDEFINED_SCENARIO;
	}

	private function isLaunchPossible(int $activityId): bool
	{
		return $activityId > 0
			&& $this->nextTarget
			&& $this->userId > 0
			&& OpenLine::isCopilotProcessingAvailable($activityId)
		;
	}

	private function isFirstOpenLineActivityForItem(): bool
	{
		$activityFields = $this->activityFields;
		$possibleTarget = $this->nextTarget;

		$this->logger->debug(
			'{date}: Trying to determine if the activity is first open line activity for item: {activity}' . PHP_EOL,
			[
				'activity' => $activityFields,
			],
		);

		$allOtherOpenLineActivityIdsOfTarget = ActivityTable::query()
			->setSelect(['ID'])
			->where('PROVIDER_ID', OpenLine::getId())
			->where('BINDINGS.OWNER_TYPE_ID', $possibleTarget->getEntityTypeId())
			->where('BINDINGS.OWNER_ID', $possibleTarget->getEntityId())
			->setLimit(100)
			->fetchCollection()
			->getIdList()
		;

		// exclude activity that we are testing right now
		$allOtherOpenLineActivityIdsOfTarget = array_diff($allOtherOpenLineActivityIdsOfTarget, [(int)$activityFields['ID']]);
		if (empty($allOtherOpenLineActivityIdsOfTarget))
		{
			$this->logger->debug(
				'{date}: No other open line activities found for target {target} {activity}' . PHP_EOL,
				[
					'target' => $possibleTarget,
					'activity' => $activityFields,
				],
			);

			return true;
		}

		return false;
	}
}
