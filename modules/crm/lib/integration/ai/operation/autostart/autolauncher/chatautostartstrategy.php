<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScenarioOverrideResolver;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\GlobalFeatureReader;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Main\DI\ServiceLocator;

final class ChatAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	private readonly ScenarioOverrideResolver $scenarioOverrideResolver;

	public function __construct(
		int $activityOperation,
		array $activityFields,
		GlobalFeatureReader $featureReader = new GlobalFeatureReader(),
	)
	{
		parent::__construct($activityOperation, $activityFields, $featureReader);

		$this->scenarioOverrideResolver = new ScenarioOverrideResolver();
	}

	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getFillFieldsSettings();
		if ($fillFieldsSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart operation: launch options not found' . PHP_EOL);

			return;
		}

		$scenario = $this->detectLaunchScenario($fillFieldsSettings);
		if ($scenario === Scenario::UNDEFINED_SCENARIO)
		{
			return;
		}

		$activityId = (int)($this->activityFields['ID'] ?? null);
		$this->logger->info(
			'{date}: Trying to autostart operation after completing the open line dialog,'
			. ' activity {activityId}, scenario {scenario}, changed fields {changedFieldsKeys}' . PHP_EOL,
			[
				'activityId' => $activityId,
				'scenario' => $scenario,
				'changedFieldsKeys' => array_keys($changedFields),
			],
		);

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
			(new StepContext(
				activityId: $activityId,
				userId: $this->userId,
				scenarioName: $scenario,
				isManualLaunch: false,
				activityProvider: OpenLine::getId(),
			))
				->withExtra(
					'fillFieldsEntityAllowed',
					CopilotSettings::getCurrent()->isFillFieldsEnabledFor($this->nextTarget?->getEntityTypeId() ?? 0),
				),
		);
	}

	private function detectLaunchScenario(FillFieldsSettings $settings): string
	{
		$channel = AutomationScenarioRegistry::CHANNEL_CHAT;
		$fillFieldsActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_FILL_FIELDS, $channel, 0);
		$summarizeActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_SUMMARIZE, $channel, 0);
		$analyzeActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION, $channel, 0);

		if (!$fillFieldsActive && !$summarizeActive && !$analyzeActive)
		{
			return Scenario::UNDEFINED_SCENARIO;
		}

		$isAutomationAllowed = $this->featureReader->isPortalAutomationAllowed();
		if (!$isAutomationAllowed)
		{
			return Scenario::UNDEFINED_SCENARIO;
		}

		$entityTypeId = $this->nextTarget?->getEntityTypeId() ?? 0;
		$isFillFieldsEnabled = AIManager::isFillFieldsEnabledForEntityType($entityTypeId);
		$isAnalyzeCommunicationEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::AnalyzeCommunication);
		$isSummarizeEnabled = AIManager::isEnabledInGlobalSettings(GlobalSetting::Summarize);
		if (
			!$isFillFieldsEnabled
			&& $fillFieldsActive
			&& $isSummarizeEnabled
			&& AIManager::isEnabledInGlobalSettings(GlobalSetting::FillItemFromCall)
		)
		{
			$this->logger->debug(
				'{date}: Fill fields autostart is disabled for entity type {entityTypeId}' . PHP_EOL,
				['entityTypeId' => $entityTypeId],
			);
		}

		$isFirstChatActivityWithFiles = null;
		$isFirstChatFor = function (string $scenarioCode) use ($settings, $channel, &$isFirstChatActivityWithFiles): bool {
			if (!$this->scenarioOverrideResolver->isFirstOnlyMode($settings, $scenarioCode, $channel))
			{
				return true;
			}

			$isFirstChatActivityWithFiles ??= $this->isFirstOpenLineActivityForItem();

			return $isFirstChatActivityWithFiles;
		};

		$shouldFillFieldsStart = $isFillFieldsEnabled
			&& $isSummarizeEnabled
			&& $fillFieldsActive
			&& $isFirstChatFor(AutomationScenarioRegistry::SCENARIO_FILL_FIELDS)
		;
		$shouldAnalyzeCommunicationStart = $isAnalyzeCommunicationEnabled
			&& $analyzeActive
			&& $isFirstChatFor(AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION)
		;
		$shouldSummarizeStart = !$shouldFillFieldsStart
			&& $isSummarizeEnabled
			&& $summarizeActive
			&& $isFirstChatFor(AutomationScenarioRegistry::SCENARIO_SUMMARIZE)
		;

		return self::resolveLaunchScenario(
			$isAutomationAllowed,
			$shouldFillFieldsStart,
			$shouldAnalyzeCommunicationStart,
			$shouldSummarizeStart,
		);
	}

	private static function resolveLaunchScenario(
		bool $isAutomationAllowed,
		bool $shouldFillFieldsStart,
		bool $shouldAnalyzeCommunicationStart,
		bool $shouldSummarizeStart,
	): string
	{
		if (!$isAutomationAllowed)
		{
			return Scenario::UNDEFINED_SCENARIO;
		}

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
