<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart\AutoLauncher;

use Bitrix\Crm\Activity\Mail\CopilotThreadCollector;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\Pipeline\PipelineExecutor;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\BaasManager;
use Bitrix\Crm\Integration\AI\Enum\GlobalSetting;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\ScenarioOverrideResolver;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Settings\CopilotSettings;
use Bitrix\Main\DI\ServiceLocator;

final class MailAutoStartStrategy extends BaseChannelAutoStartStrategy
{
	private readonly ScenarioOverrideResolver $scenarioOverrideResolver;

	public function __construct(int $activityOperation, array $activityFields)
	{
		parent::__construct($activityOperation, $activityFields);
		$this->scenarioOverrideResolver = new ScenarioOverrideResolver();
	}

	public function run(array $changedFields = []): void
	{
		$fillFieldsSettings = $this->getFillFieldsSettings();
		if ($fillFieldsSettings === null)
		{
			$this->logger->debug('{date}: Unable to autostart email operation: launch options not found' . PHP_EOL);

			return;
		}

		$scenario = $this->detectLaunchScenario($fillFieldsSettings);
		if ($scenario === Scenario::UNDEFINED_SCENARIO)
		{
			return;
		}

		$activityId = (int)($this->activityFields['ID'] ?? null);
		$this->logger->info(
			'{date}: Trying to autostart operation after email activity {activityId} was added,'
			. ' scenario {scenario}' . PHP_EOL,
			[
				'activityId' => $activityId,
				'scenario' => $scenario,
			],
		);

		if (!$this->isLaunchPossible($activityId))
		{
			$this->logger->debug('{date}: Unable to autostart email operation: AI operation in CRM is not possible' . PHP_EOL);

			return;
		}

		if (!$this->isTriggerEmailBodySynced($activityId))
		{
			$this->logger->debug(
				'{date}: Unable to autostart email operation: trigger email {activityId} body is not downloaded yet' . PHP_EOL,
				[
					'activityId' => $activityId,
				],
			);

			return;
		}

		$anchorActivityId = $this->resolveAnchorActivityId($activityId);

		$this->logger->info(
			'{date}: Trying to autostart email operation with scenario "{scenario}", anchor {anchorActivityId}' . PHP_EOL,
			[
				'scenario' => $scenario,
				'anchorActivityId' => $anchorActivityId,
			],
		);

		$ownerTypeId = $this->nextTarget?->getEntityTypeId() ?? 0;
		$ownerId = $this->nextTarget?->getEntityId() ?? 0;
		$triggerDirection = (int)($this->activityFields['DIRECTION'] ?? \CCrmActivityDirection::Undefined);

		ServiceLocator::getInstance()->get(PipelineExecutor::class)->startOrResume(
			(new StepContext(
				activityId: $anchorActivityId,
				userId: $this->userId,
				scenarioName: $scenario,
				isManualLaunch: false,
				activityProvider: Email::getId(),
			))
				->withExtra('targetOwnerTypeId', $ownerTypeId)
				->withExtra('targetOwnerId', $ownerId)
				->withExtra('triggerDirection', $triggerDirection)
				->withExtra(
					'fillFieldsEntityAllowed',
					CopilotSettings::getCurrent()->isFillFieldsEnabledFor($ownerTypeId),
				),
		);
	}

	private function detectLaunchScenario(FillFieldsSettings $settings): string
	{
		$channel = AutomationScenarioRegistry::CHANNEL_EMAIL;
		$direction = (int)($this->activityFields['DIRECTION'] ?? \CCrmActivityDirection::Undefined);
		$fillFieldsActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_FILL_FIELDS, $channel, $direction);
		$summarizeActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_SUMMARIZE, $channel, $direction);
		$analyzeActive = $this->scenarioOverrideResolver->isScenarioActive($settings, AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION, $channel, $direction);

		if (!$fillFieldsActive && !$summarizeActive && !$analyzeActive)
		{
			return Scenario::UNDEFINED_SCENARIO;
		}

		$isAutomationAllowed = self::isPortalAutomationAllowed();
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

		$isFirstEmailActivityForItem = null;
		$isFirstEmailFor = function (string $scenarioCode) use ($settings, $channel, &$isFirstEmailActivityForItem): bool {
			if (!$this->scenarioOverrideResolver->isFirstOnlyMode($settings, $scenarioCode, $channel))
			{
				return true;
			}

			$isFirstEmailActivityForItem ??= $this->isFirstEmailActivityForItem();

			return $isFirstEmailActivityForItem;
		};

		$shouldFillFieldsStart = $isFillFieldsEnabled
			&& $isSummarizeEnabled
			&& $fillFieldsActive
			&& $isFirstEmailFor(AutomationScenarioRegistry::SCENARIO_FILL_FIELDS)
		;
		$shouldAnalyzeCommunicationStart = $isAnalyzeCommunicationEnabled
			&& $analyzeActive
			&& $isFirstEmailFor(AutomationScenarioRegistry::SCENARIO_ANALYZE_COMMUNICATION)
		;
		$shouldSummarizeStart = !$shouldFillFieldsStart
			&& $isSummarizeEnabled
			&& $summarizeActive
			&& $isFirstEmailFor(AutomationScenarioRegistry::SCENARIO_SUMMARIZE)
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

	private static function isPortalAutomationAllowed(): bool
	{
		return AIManager::isAiCallAutomaticProcessingAllowed()
			&& BaasManager::hasPackage()
		;
	}

	private function isLaunchPossible(int $activityId): bool
	{
		return $activityId > 0
			&& $this->nextTarget
			&& $this->userId > 0
		;
	}

	private function resolveAnchorActivityId(int $activityId): int
	{
		$activityFields = $this->activityFields;
		$threadId = (int)($activityFields['THREAD_ID'] ?? 0);
		$ownerTypeId = $this->nextTarget?->getEntityTypeId() ?? 0;
		$ownerId = $this->nextTarget?->getEntityId() ?? 0;

		if ($threadId > 0 && $ownerTypeId > 0 && $ownerId > 0)
		{
			$root = CopilotThreadCollector::findEntityLocalRoot($threadId, $ownerTypeId, $ownerId);
			if ($root && isset($root['ID']) && (int)$root['ID'] > 0)
			{
				return (int)$root['ID'];
			}
		}

		return $activityId;
	}

	private function isFirstEmailActivityForItem(): bool
	{
		$activityFields = $this->activityFields;
		$possibleTarget = $this->nextTarget;

		$this->logger->debug(
			'{date}: Trying to determine if the activity is first email activity for item: {activityId}' . PHP_EOL,
			[
				'activityId' => (int)($activityFields['ID'] ?? 0),
			],
		);

		$allOtherEmailActivityIdsOfTarget = ActivityTable::query()
			->setSelect(['ID'])
			->where('PROVIDER_ID', Email::getId())
			->where('DIRECTION', \CCrmActivityDirection::Incoming)
			->where('BINDINGS.OWNER_TYPE_ID', $possibleTarget->getEntityTypeId())
			->where('BINDINGS.OWNER_ID', $possibleTarget->getEntityId())
			->setOrder(['ID' => 'ASC'])
			->setLimit(100)
			->fetchCollection()
			->getIdList()
		;

		$allOtherEmailActivityIdsOfTarget = array_diff($allOtherEmailActivityIdsOfTarget, [(int)$activityFields['ID']]);
		if (empty($allOtherEmailActivityIdsOfTarget))
		{
			$this->logger->debug(
				'{date}: No other email activities found for target {target} {activityId}' . PHP_EOL,
				[
					'target' => $possibleTarget,
					'activityId' => (int)($activityFields['ID'] ?? 0),
				],
			);

			return true;
		}

		$syncedOtherEmailActivityIds = CopilotThreadCollector::filterSyncedActivityIds(
			$this->loadActivitiesWithMailMessage($allOtherEmailActivityIdsOfTarget)
		);
		if (empty($syncedOtherEmailActivityIds))
		{
			$this->logger->debug(
				'{date}: Only email activities with not yet downloaded body found for target {target}, treating current as first' . PHP_EOL,
				[
					'target' => $possibleTarget,
				],
			);

			return true;
		}

		return false;
	}

	private function isTriggerEmailBodySynced(int $activityId): bool
	{
		$messageId = (int)($this->activityFields['UF_MAIL_MESSAGE'] ?? 0);
		if ($messageId <= 0)
		{
			$rows = $this->loadActivitiesWithMailMessage([$activityId]);
			$messageId = (int)($rows[0]['UF_MAIL_MESSAGE'] ?? 0);
		}

		if ($messageId <= 0)
		{
			return true;
		}

		$syncedIds = CopilotThreadCollector::filterSyncedActivityIds([
			['ID' => $activityId, 'UF_MAIL_MESSAGE' => $messageId],
		]);

		return in_array($activityId, $syncedIds, true);
	}

	private function loadActivitiesWithMailMessage(array $activityIds): array
	{
		if (empty($activityIds))
		{
			return [];
		}

		$rows = [];
		$res = \CCrmActivity::getList(
			[],
			['@ID' => $activityIds, 'CHECK_PERMISSIONS' => 'N'],
			false,
			false,
			['ID', 'UF_MAIL_MESSAGE'],
		);
		while ($row = $res->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}
}
